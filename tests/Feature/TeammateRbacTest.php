<?php

use App\Traits\InteractsWithTeammateRbac;

function teammateRbac(): object
{
    return new class
    {
        use InteractsWithTeammateRbac;
    };
}

test('presets map to built-in ClusterRoles, defaulting to edit', function (): void {
    $t = teammateRbac();

    expect($t->presetClusterRole(read: false, edit: false, admin: false))->toBe('edit');   // default
    expect($t->presetClusterRole(read: false, edit: true, admin: false))->toBe('edit');
    expect($t->presetClusterRole(read: true, edit: false, admin: false))->toBe('view')
        ->and($t->presetClusterRole(read: false, edit: false, admin: true))->toBe('admin')
        ->and($t->presetClusterRole(read: true, edit: false, admin: true))->toBe('admin');     // admin wins
});

test('admin escalates to the real cluster-admin ClusterRole when granted cluster-wide', function (): void {
    $t = teammateRbac();

    // Bound via a ClusterRoleBinding, the built-in `admin` role excludes
    // cluster-scoped resources (Nodes, StorageClasses, CRDs, ClusterRoles) —
    // only `cluster-admin` actually reaches those, so --admin --cluster must
    // resolve to it rather than silently under-delivering.
    expect($t->presetClusterRole(read: false, edit: false, admin: true, clusterWide: true))->toBe('cluster-admin');
    expect($t->presetClusterRole(read: false, edit: false, admin: true, clusterWide: false))->toBe('admin');

    // view/edit are unaffected by cluster-wide scope — only admin escalates.
    expect($t->presetClusterRole(read: true, edit: false, admin: false, clusterWide: true))->toBe('view');
    expect($t->presetClusterRole(read: false, edit: true, admin: false, clusterWide: true))->toBe('edit');
});

test('the context name is meaningful (app+env namespace), not the cluster host', function (): void {
    expect(teammateRbac()->teammateContextName('react-test-production'))->toBe('larakube-react-test-production')
        ->and(teammateRbac()->teammateContextName(''))->toBe('larakube-cluster');
});

test('a person name becomes a k8s-safe ServiceAccount name', function (): void {
    $t = teammateRbac();

    expect($t->teammateSaName('Lloyd'))->toBe('lloyd')
        ->and($t->teammateSaName('Mary Jane'))->toBe('mary-jane');
});

test('the identity manifest is a central SA + bound-token Secret, labeled per user', function (): void {
    $yaml = teammateRbac()->teammateIdentityManifest('larakube-access', 'lloyd', 'Lloyd');

    expect($yaml)
        ->toContain('kind: ServiceAccount')
        ->toContain('namespace: larakube-access')
        ->toContain('type: kubernetes.io/service-account-token')
        ->toContain('larakube.dev/access-user: lloyd');
});

test('the binding lives in the APP namespace and references a built-in ClusterRole', function (): void {
    $yaml = teammateRbac()->teammateBindingManifest('blue-production', 'larakube-access', 'lloyd', 'edit');

    expect($yaml)
        ->toContain('kind: RoleBinding')
        ->toContain('namespace: blue-production')          // binding lives in the app ns
        ->toContain('name: larakube-user-lloyd')
        ->toContain('namespace: larakube-access')          // subject SA is central
        ->toContain('kind: ClusterRole')
        ->toContain('name: edit')
        ->toContain('larakube.dev/access-user: lloyd');
});

test('the teammate kubeconfig names the context for the CLUSTER and defaults to the app namespace', function (): void {
    $kubeconfig = teammateRbac()->assembleTeammateKubeconfig(
        contextName: 'larakube-167.71.214.14',
        server: 'https://167.71.214.14:6443',
        caData: 'CA==',
        defaultNamespace: 'blue-production',
        token: 'TOK',
        user: 'lloyd',
    );

    expect($kubeconfig)
        ->toContain('current-context: larakube-167.71.214.14')
        ->toContain('namespace: blue-production')
        ->toContain('token: TOK')
        ->toContain('name: lloyd');
});
