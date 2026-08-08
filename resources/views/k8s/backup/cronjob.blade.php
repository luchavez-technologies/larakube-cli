{{-- Folded into the config-checksum so a template-text-only edit still rolls
     the CronJob's next Job — the script lives in the manifest, not a mounted
     file, so nothing else would notice a change. --}}
@php
    $__tplHash = substr(hash_file('sha256', resource_path('views/k8s/backup/cronjob.blade.php')), 0, 12);

    // Rendered from InteractsWithBackup::backupVolumeTargets() so the schedule
    // and `backup:run` can never disagree about what is worth keeping.
    $namespaces = collect($volumes)->pluck('namespace')->push('larakube-plex')->unique()->sort()->values();
@endphp
apiVersion: v1
kind: ServiceAccount
metadata:
  name: larakube-backup
  namespace: larakube-shared
---
# The job streams dumps out through `kubectl exec` rather than mounting the six
# ReadWriteOnce volumes it would otherwise need — that would pin the job to one
# node and couple it to every tool's storage layout.
#
# The cost is this permission set. `pods/exec` is effectively root inside those
# pods, so it is scoped by RoleBinding to only the namespaces that actually hold
# backed-up data, and to no others.
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: larakube-backup
rules:
  - apiGroups: [""]
    resources: ["pods"]
    verbs: ["get", "list"]
  - apiGroups: [""]
    resources: ["pods/exec"]
    verbs: ["create"]
  - apiGroups: ["apps"]
    resources: ["deployments"]
    verbs: ["get", "list"]
@foreach($namespaces as $ns)
---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: larakube-backup
  namespace: {{ $ns }}
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: larakube-backup
subjects:
  - kind: ServiceAccount
    name: larakube-backup
    namespace: larakube-shared
@endforeach
---
apiVersion: batch/v1
kind: CronJob
metadata:
  name: larakube-backup
  namespace: larakube-shared
  labels:
    app: larakube-backup
spec:
  schedule: "{{ $schedule }}"
  # Without this, Kubernetes interprets the schedule in the
  # kube-controller-manager's timezone — UTC on essentially every cluster. A
  # "3am" backup then lands mid-morning for anyone east of London, running
  # pg_dump and a multi-megabyte upload straight through their business hours.
  # Requires Kubernetes >= 1.27.
  timeZone: "{{ $timezone }}"
  # Two concurrent backups would interleave dumps into one corrupt archive.
  concurrencyPolicy: Forbid
  successfulJobsHistoryLimit: 1
  failedJobsHistoryLimit: 3
  jobTemplate:
    spec:
      backoffLimit: 2
      template:
        metadata:
          annotations:
            larakube.io/config-checksum: "{{ substr(hash('sha256', $schedule.$timezone.$__tplHash.json_encode($volumes)), 0, 16) }}"
        spec:
          restartPolicy: OnFailure
          serviceAccountName: larakube-backup
          volumes:
            - name: work
              emptyDir: {}
          initContainers:
            # Dump. kubectl reaches each tool's own pod, so pg_dump and tar run
            # where the data already is — this image needs neither.
            #
            # Not bitnami/kubectl: Bitnami withdrew their Docker Hub images in
            # 2025 and the tag now 404s. alpine/k8s is maintained and carries a
            # shell, which the official registry.k8s.io/kubectl (distroless)
            # does not.
            - name: dump
              image: alpine/k8s:1.34.1
              command:
                - /bin/bash
                - -c
                - |
                  set -euo pipefail
                  cd /work

                  # Every stage reads this. Generating the stamp at upload time
                  # instead would name the prefix an hour after the dumps it
                  # holds, and two stages could disagree about where they are.
                  date +%Y-%m-%d-%H%M%S > STAMP
                  echo "› backup $(cat STAMP)"

                  echo "› databases ({{ $dbDriver }})"
                  DBS=$(kubectl exec deploy/{{ $dbService }} -n larakube-plex -c {{ $dbService }} -- \
                    sh -c {!! escapeshellarg($dbListCommand) !!})

                  if [ -z "$DBS" ]; then
                    echo "no databases found — refusing to upload an empty backup" >&2
                    exit 1
                  fi

                  for db in $DBS; do
                    db=$(echo "$db" | tr -d '\r')
                    [ -z "$db" ] && continue
                    echo "  $db"
                    CMD=$(printf '%s' {!! escapeshellarg($dbDumpTemplate) !!} | sed "s|__DB__|$db|g")
                    kubectl exec deploy/{{ $dbService }} -n larakube-plex -c {{ $dbService }} -- \
                      sh -c "$CMD" | gzip > "db-$db.sql.gz"
                    # A dump that exits 0 having written nothing is a real
                    # failure mode; treat a stub file as one.
                    [ "$(stat -c%s "db-$db.sql.gz")" -ge 100 ] || { echo "dump of $db is empty" >&2; exit 1; }
                  done

                  echo "› volumes"
@foreach($volumes as $v)
                  echo "  {{ $v['name'] }}"
                  kubectl exec deploy/{{ $v['deployment'] }} -n {{ $v['namespace'] }} -c {{ $v['container'] }} -- \
                    tar czf - -C {{ dirname($v['path']) }} {{ basename($v['path']) }} > "vol-{{ $v['name'] }}.tar.gz"
                  [ "$(stat -c%s "vol-{{ $v['name'] }}.tar.gz")" -ge 50 ] || { echo "archive of {{ $v['name'] }} is empty" >&2; exit 1; }
@endforeach

                  ls -lh db-*.sql.gz vol-*.tar.gz
              volumeMounts:
                - name: work
                  mountPath: /work
              resources:
                requests: { memory: 128Mi, cpu: 100m }
                limits: { memory: 1Gi, cpu: 1000m }
            # Encrypt. A separate stage only because no maintained image ships
            # both kubectl and the openssl CLI — alpine/k8s has no openssl and
            # amazon/aws-cli has neither.
            #
            # Deliberately NOT the Commons database image: that would only be
            # "already on the node" for whichever engine this cluster happens to
            # run, and would need re-checking for openssl per driver.
            #
            # This is not an isolation boundary: every container here shares the
            # same emptyDir, so the plaintext dumps are visible to all of them
            # until this step replaces them. The boundary that matters is that
            # nothing unencrypted is ever uploaded.
            - name: encrypt
              image: alpine/openssl:3.5.4
              command:
                - /bin/sh
                - -c
                - |
                  set -eu
                  cd /work
                  for f in db-*.sql.gz vol-*.tar.gz; do
                    [ -e "$f" ] || continue
                    openssl enc -aes-256-cbc -pbkdf2 -salt -pass "env:PASSPHRASE" \
                      -in "$f" -out "$f.enc"
                    rm -f "$f"
                  done
                  ls -lh ./*.enc
              env:
                - name: PASSPHRASE
                  valueFrom:
                    secretKeyRef: { name: larakube-backup-config, key: passphrase }
              volumeMounts:
                - name: work
                  mountPath: /work
              resources:
                requests: { memory: 64Mi, cpu: 100m }
                limits: { memory: 512Mi, cpu: 1000m }
          containers:
            # Upload only. No plaintext dump exists by this point.
            - name: upload
              image: amazon/aws-cli:2.27.22
              command:
                - /bin/sh
                - -c
                - |
                  set -eu
                  cd /work
                  PREFIX="larakube/$(cat STAMP)"
                  ITEMS=""

                  for f in ./*.enc; do
                    f="${f#./}"
                    base="${f%.enc}"
                    case "$base" in
                      db-*)  KIND=database; NAME="${base#db-}";  NAME="${NAME%.sql.gz}" ;;
                      vol-*) KIND=volume;   NAME="${base#vol-}"; NAME="${NAME%.tar.gz}" ;;
                      *) continue ;;
                    esac
                    aws --endpoint-url "$ENDPOINT" --no-progress s3 cp "$f" "s3://$BUCKET/$PREFIX/$f"
                    ITEMS="$ITEMS{\"kind\":\"$KIND\",\"name\":\"$NAME\",\"object\":\"$f\",\"bytes\":$(stat -c%s "$f")},"
                  done

                  # LAST, and only if every object above landed: the manifest is
                  # the commit marker. Without it this prefix is invisible to
                  # backup:list and refused by backup:restore, which is exactly
                  # what a half-uploaded backup should be.
                  printf '{"version":1,"taken_at":"%s","engine":"%s","items":[%s]}' \
                    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "{{ $dbDriver }}" "${ITEMS%,}" > manifest.json
                  aws --endpoint-url "$ENDPOINT" --no-progress s3 cp manifest.json "s3://$BUCKET/$PREFIX/manifest.json"
                  echo "stored s3://$BUCKET/$PREFIX/ ($(echo "$ITEMS" | tr -cd ',' | wc -c) objects)"
              env:
                - name: ENDPOINT
                  valueFrom:
                    secretKeyRef: { name: larakube-backup-config, key: endpoint }
                - name: BUCKET
                  valueFrom:
                    secretKeyRef: { name: larakube-backup-config, key: bucket }
                - name: AWS_ACCESS_KEY_ID
                  valueFrom:
                    secretKeyRef: { name: larakube-backup-config, key: access-key }
                - name: AWS_SECRET_ACCESS_KEY
                  valueFrom:
                    secretKeyRef: { name: larakube-backup-config, key: secret-key }
                - name: AWS_DEFAULT_REGION
                  valueFrom:
                    secretKeyRef: { name: larakube-backup-config, key: region }
                # From aws-cli 2.23 the client sends x-amz-checksum-crc32 by
                # default, which R2, B2 and MinIO reject with an opaque error.
                - name: AWS_REQUEST_CHECKSUM_CALCULATION
                  value: "when_required"
                - name: AWS_RESPONSE_CHECKSUM_VALIDATION
                  value: "when_required"
              volumeMounts:
                - name: work
                  mountPath: /work
              resources:
                requests: { memory: 64Mi, cpu: 50m }
                limits: { memory: 512Mi, cpu: 500m }
