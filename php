#!/bin/bash

# LaraKube Professional Development Runner (Persistent Daemon Version)
# Supports PHP, Docker, and Kubectl for orchestration development

CLI_DIR=$(cd "$(dirname "$0")" && pwd)
PROJECT_DIR=$(pwd)
USER_ID=$(id -u)
GROUP_ID=$(id -g)
HOST_HOME="$HOME"
DOCKER_SOCK="/var/run/docker.sock"
CONTAINER_NAME="larakube-php-cli"

# Optimized Execution Logic (CI vs Local)
execute() {
    local php_flags=()
    local cmd_args=()
    local skip_next=false

    # Separate PHP flags from the actual command
    for (( i=1; i<=$#; i++ )); do
        arg="${!i}"
        
        if [[ "$skip_next" == true ]]; then
            php_flags+=("$arg")
            skip_next=false
            continue
        fi

        if [[ "$arg" =~ ^- ]]; then
            php_flags+=("$arg")
            # PHP flags that take an argument
            if [[ "$arg" == "-d" || "$arg" == "-c" || "$arg" == "-f" ]]; then
                skip_next=true
            fi
        else
            # Everything from the first non-flag onwards is the command and its args
            for (( j=i; j<=$#; j++ )); do
                cmd_args+=("${!j}")
            done
            break
        fi
    done

    local first_cmd="${cmd_args[0]}"

    # If it's a known native tool or an executable file, run it directly
    if [[ "$first_cmd" == "composer" || "$first_cmd" == "docker" || "$first_cmd" == "kubectl" || "$first_cmd" == "larakube" ]] || command -v "$first_cmd" >/dev/null 2>&1; then
        local larakube_binary="/larakube/larakube"
        if [ "$GITHUB_ACTIONS" = "true" ]; then
            larakube_binary="$CLI_DIR/larakube"
        fi

        if [[ "$first_cmd" == "php" ]]; then
            # If we are running 'php larakube ...', we need to fix the path
            local final_args=()
            for arg in "${cmd_args[@]:1}"; do
                if [[ "$arg" == "larakube" ]]; then
                    final_args+=("$larakube_binary")
                else
                    final_args+=("$arg")
                fi
            done
            php -d memory_limit=2G "${php_flags[@]}" "${final_args[@]}"
        elif [[ "$first_cmd" == "larakube" ]]; then
            php -d memory_limit=2G "${php_flags[@]}" "$larakube_binary" "${cmd_args[@]:1}"
        elif [[ "$first_cmd" == vendor/bin/* ]]; then
            php -d memory_limit=2G "${php_flags[@]}" "${cmd_args[@]}"
        else
            "${cmd_args[@]}"
        fi
    else
        # Otherwise, run via PHP with flags
        php -d memory_limit=2G "${php_flags[@]}" "${cmd_args[@]}"
    fi
}

# ⚡️ CI/CD SHORT-CIRCUIT: If running in GitHub Actions, skip all Docker logic
if [ "$GITHUB_ACTIONS" = "true" ]; then
    execute "$@"
    exit $?
fi

# Detect Architecture for tool installation (Local only)
ARCH=$(uname -m)
[ "$ARCH" = "arm64" ] && K8S_ARCH="arm64" || K8S_ARCH="amd64"
[ "$ARCH" = "arm64" ] && DOCKER_ARCH="aarch64" || DOCKER_ARCH="x86_64"

# Handle "stop" command to cleanup the daemon
if [ "$1" = "stop" ]; then
    echo "🛑 Stopping LaraKube PHP CLI Daemon..."
    docker stop "$CONTAINER_NAME" > /dev/null 2>&1 || true
    docker rm "$CONTAINER_NAME" > /dev/null 2>&1 || true
    exit 0
fi

# Ensure the daemon is running (Local only)
if ! docker ps --format '{{.Names}}' | grep -q "^$CONTAINER_NAME$"; then
    echo "🚀 Starting LaraKube PHP CLI Daemon..."
    
    # Remove any stale container
    docker rm -f "$CONTAINER_NAME" > /dev/null 2>&1 || true

    # Detect the actual Docker socket (handling symlinks like OrbStack)
    REAL_DOCKER_SOCK="$DOCKER_SOCK"
    if [ -L "$DOCKER_SOCK" ]; then
        REAL_DOCKER_SOCK=$(readlink "$DOCKER_SOCK")
    fi

    # Start the container in background
    docker run -d \
        --name "$CONTAINER_NAME" \
        -v "$PROJECT_DIR":"$PROJECT_DIR" \
        -v "$CLI_DIR":/larakube \
        -v "$HOST_HOME":/home/php \
        -v "$REAL_DOCKER_SOCK":/var/run/docker.sock \
        --add-host=host.docker.internal:host-gateway \
        -w "$PROJECT_DIR" \
        --user "$USER_ID:$GROUP_ID" \
        -e HOME=/home/php \
        -e COMPOSER_ALLOW_SUPERUSER=1 \
        -e SHOW_WELCOME_MESSAGE=false \
        -e PHP_MEMORY_LIMIT=2G \
        -e KUBECONFIG=/home/php/.kube/config-container \
        -e DOCKER_HOST=unix:///var/run/docker.sock \
        --entrypoint /bin/sh \
        serversideup/php:8.4-cli \
        -c "tail -f /dev/null" > /dev/null

    # Install tools once (Silently)
    echo "🛠 Preparing daemon environment..."
    docker exec --user 0:0 "$CONTAINER_NAME" /bin/bash -c "
        # Ensure socket is accessible
        chmod 666 /var/run/docker.sock

        if ! command -v kubectl > /dev/null; then
            curl -LOs \"https://dl.k8s.io/release/\$(curl -L -s https://dl.k8s.io/release/stable.txt)/bin/linux/$K8S_ARCH/kubectl\"
            chmod +x kubectl && mv kubectl /usr/local/bin/ > /dev/null 2>&1
        fi
        if ! command -v docker > /dev/null; then
            curl -fsSLs https://download.docker.com/linux/static/stable/$DOCKER_ARCH/docker-27.3.1.tgz | tar xz -C /tmp/ && mv /tmp/docker/docker /usr/local/bin/ > /dev/null 2>&1
        fi

        # Patch Kubeconfig for container-to-host connectivity
        if [ -f /home/php/.kube/config ]; then
            sed 's/127.0.0.1/host.docker.internal/g' /home/php/.kube/config > /home/php/.kube/config-container
            chmod 600 /home/php/.kube/config-container
            chown $USER_ID:$GROUP_ID /home/php/.kube/config-container
        fi
    "
fi

# Determine if we should run interactively
INTERACTIVE="-i"
[ -t 0 ] && INTERACTIVE="-it"

# Pass GITHUB_TOKEN if it exists on the host
ENV_ARGS=""
if [ ! -z "$GITHUB_TOKEN" ]; then
    ENV_ARGS="-e GITHUB_TOKEN=$GITHUB_TOKEN"
fi

# Detect if we are in CI
if [ "$GITHUB_ACTIONS" == "true" ]; then
    execute "$@"
    exit $?
fi

# Local development: run inside Docker
docker exec $INTERACTIVE $ENV_ARGS "$CONTAINER_NAME" /bin/bash -c "
    $(declare -f execute)
    execute \"\$@\"
" -- "$@"
