{{-- Spring Boot production image: build the runnable jar with Gradle, then run
     it on a JRE. The scaffold has no Gradle wrapper, so the build image
     supplies Gradle. Runtime configuration arrives through the environment
     (SPRING_* variables), so nothing from .env is read here. --}}
############################################
# Build
############################################
FROM docker.io/library/gradle:8-jdk21 AS builder
WORKDIR /src

# Build files first: dependencies only download again when they change.
COPY build.gradle* settings.gradle* ./
RUN gradle dependencies --no-daemon -q > /dev/null 2>&1 || true

COPY . .
RUN gradle bootJar --no-daemon -x test \
    && cp "$(ls build/libs/*.jar | grep -v -- '-plain' | head -n 1)" /app.jar

############################################
# Production Image
############################################
FROM docker.io/library/eclipse-temurin:21-jre-alpine AS deploy
RUN adduser -D -u 10001 app
WORKDIR /app

COPY --from=builder /app.jar ./app.jar

USER app
EXPOSE {{ $config->framework->containerPort() }}

ENTRYPOINT ["java", "-XX:MaxRAMPercentage=75", "-jar", "app.jar"]
