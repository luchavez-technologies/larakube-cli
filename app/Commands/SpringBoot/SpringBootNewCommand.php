<?php

namespace App\Commands\SpringBoot;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Traits\AsksServerStack;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class SpringBootNewCommand extends Command
{
    use AsksServerStack, CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'springboot:new
                            {name? : The name of the Spring Boot application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new Spring Boot 3.4 (Java 21 LTS) application with Kubernetes infrastructure';

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();

        if (! $this->checkPrerequisites(false)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your Spring Boot application?',
            placeholder: 'my-springboot-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        $database = $this->askDatabase(AppFramework::SPRINGBOOT, 'Which database engine would you like to use?');
        $cacheDriver = $this->askCache(AppFramework::SPRINGBOOT);
        $objectStorage = $this->askStorage(AppFramework::SPRINGBOOT);
        $scoutDriver = $this->askSearch(AppFramework::SPRINGBOOT);

        // Build ConfigData
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::SPRINGBOOT;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding Spring Boot (Java 21 LTS): $appName...");

        // 5. Create directory structure
        if (! is_dir($projectDir)) {
            mkdir($projectDir, 0o755, true);
        }

        // 6. Generate Spring Boot Gradle Kotlin DSL files & Application class
        $this->generateSpringBootScaffolding($projectDir, $appName, $database, $cacheDriver);

        // 7. Generate K8s manifests
        $this->withSpin('Orchestrating Spring Boot infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ Spring Boot project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your Spring Boot application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Features configured:</>');
        $this->line('  <fg=gray>  • Spring Boot 3.4 + Java 21 LTS runner (eclipse-temurin:21-jre-alpine)</>');
        $this->line('  <fg=gray>  • Production image built from Dockerfile.springboot (Gradle build, then a slim JRE)</>');
        $this->line('  <fg=gray>  • Spring Boot Actuator health endpoint at /actuator/health</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Generate Spring Boot scaffolding files (build.gradle.kts, settings.gradle.kts, DemoApplication.java).
     */
    protected function generateSpringBootScaffolding(
        string $projectDir,
        string $appName,
        DatabaseDriver $database,
        CacheDriver $cacheDriver,
    ): void {
        // settings.gradle.kts
        file_put_contents("$projectDir/settings.gradle.kts", "rootProject.name = \"$appName\"\n");

        // build.gradle.kts
        $buildGradle = <<<'KTS'
plugins {
    java
    id("org.springframework.boot") version "3.4.0"
    id("io.spring.dependency-management") version "1.1.6"
}

group = "com.example"
version = "0.0.1-SNAPSHOT"

java {
    toolchain {
        languageVersion = JavaLanguageVersion.of(21)
    }
}

repositories {
    mavenCentral()
}

dependencies {
    implementation("org.springframework.boot:spring-boot-starter-web")
    implementation("org.springframework.boot:spring-boot-starter-actuator")
    testImplementation("org.springframework.boot:spring-boot-starter-test")
}

tasks.withType<Test> {
    useJUnitPlatform()
}
KTS;
        file_put_contents("$projectDir/build.gradle.kts", $buildGradle);

        // Source directory
        $srcDir = "$projectDir/src/main/java/com/example/demo";
        if (! is_dir($srcDir)) {
            mkdir($srcDir, 0o755, true);
        }

        // DemoApplication.java
        $appJava = <<<'JAVA'
package com.example.demo;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.Map;

@SpringBootApplication
@RestController
public class DemoApplication {

    public static void main(String[] args) {
        SpringApplication.run(DemoApplication.class, args);
    }

    @GetMapping("/")
    public Map<String, String> root() {
        return Map.of("message", "Welcome to Spring Boot on LaraKube!");
    }
}
JAVA;
        file_put_contents("$srcDir/DemoApplication.java", $appJava);

        // application.properties in resources
        $resourcesDir = "$projectDir/src/main/resources";
        if (! is_dir($resourcesDir)) {
            mkdir($resourcesDir, 0o755, true);
        }

        $appProps = <<<'PROPS'
management.endpoints.web.exposure.include=health,info,prometheus
management.endpoint.health.show-details=always
server.port=8080
PROPS;
        file_put_contents("$resourcesDir/application.properties", $appProps);

        $this->laraKubeInfo('Generated Spring Boot build.gradle.kts and Application source.');
    }
}
