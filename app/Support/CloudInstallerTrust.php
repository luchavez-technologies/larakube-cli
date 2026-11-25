<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Lets LaraKube Cloud install apps on a server without holding standing admin access. The k3s API
 * server trusts short-lived tokens from one issuer, for one audience (this server only), for one
 * subject (the customer's cloud identity). Those tokens map to a group bound to cluster-admin, so
 * deleting that binding revokes Cloud at once. See plans/active/cloud-installs-with-short-lived-tokens.md.
 */
final readonly class CloudInstallerTrust
{
    public const string DIRECTORY = '/etc/rancher/k3s';

    public const string AUTH_FILE = self::DIRECTORY.'/larakube-cloud-auth.yaml';

    /** A k3s drop-in, so a tool that rewrites config.yaml keeps this flag. */
    public const string DROP_IN = self::DIRECTORY.'/config.yaml.d/50-larakube-cloud.yaml';

    public const string GROUP = 'larakube-cloud:installers';

    public const string BINDING = 'larakube-cloud-installers';

    public function __construct(
        public string $issuer,
        public string $audience,
        public string $subject,
    ) {
        // Each value lands in YAML, and the subject inside a CEL expression, so only plain
        // characters get through.
        if (preg_match('#^https://[a-z0-9.-]+(/[A-Za-z0-9._/-]*)?$#', $issuer) !== 1) {
            throw new InvalidArgumentException('The installer issuer must be an https URL.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9:._-]{2,127}$/', $audience) !== 1) {
            throw new InvalidArgumentException('The installer audience may only use lowercase letters, digits and : . _ -');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._@:-]{0,254}$/', $subject) !== 1) {
            throw new InvalidArgumentException('The installer subject may only use letters, digits and . _ @ : -');
        }
    }

    /** @return self|null null when none of the three options were given */
    public static function fromOptions(?string $issuer, ?string $audience, ?string $subject): ?self
    {
        $given = array_filter([$issuer, $audience, $subject], fn (?string $value): bool => $value !== null && $value !== '');

        if ($given === []) {
            return null;
        }

        if (count($given) !== 3) {
            throw new InvalidArgumentException('--cloud-installer-issuer, --cloud-installer-audience and --cloud-installer-subject go together.');
        }

        return new self((string) $issuer, (string) $audience, (string) $subject);
    }

    public function authenticationConfig(): string
    {
        return <<<YAML
        apiVersion: apiserver.config.k8s.io/v1
        kind: AuthenticationConfiguration
        jwt:
          - issuer:
              url: {$this->issuer}
              audiences:
                - {$this->audience}
            claimValidationRules:
              - expression: "claims.sub == '{$this->subject}'"
                message: only the connected cloud identity may sign in
            claimMappings:
              username:
                expression: "'larakube-cloud:' + claims.sub"
              groups:
                expression: "['larakube-cloud:installers']"

        YAML;
    }

    public function dropIn(): string
    {
        return "kube-apiserver-arg+:\n  - \"authentication-config=".self::AUTH_FILE."\"\n";
    }

    /** Written before k3s is installed, so the API server starts with the trust in place. */
    public function nodeScript(): string
    {
        $auth = base64_encode($this->authenticationConfig());
        $dropIn = base64_encode($this->dropIn());
        $directory = self::DIRECTORY;
        $authFile = self::AUTH_FILE;
        $dropInFile = self::DROP_IN;

        return <<<BASH
        mkdir -p {$directory}/config.yaml.d
        echo {$auth} | base64 -d > {$authFile}
        echo {$dropIn} | base64 -d > {$dropInFile}
        chmod 600 {$authFile} {$dropInFile}
        BASH;
    }

    public static function bindingManifest(): string
    {
        $binding = self::BINDING;
        $group = self::GROUP;

        return <<<YAML
        apiVersion: rbac.authorization.k8s.io/v1
        kind: ClusterRoleBinding
        metadata:
          name: {$binding}
          labels:
            app.kubernetes.io/managed-by: larakube
        roleRef:
          apiGroup: rbac.authorization.k8s.io
          kind: ClusterRole
          name: cluster-admin
        subjects:
          - apiGroup: rbac.authorization.k8s.io
            kind: Group
            name: {$group}

        YAML;
    }
}
