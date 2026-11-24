<?php

test('app workloads disable Kubernetes service link variables', function (string $template): void {
    $source = (string) file_get_contents(resource_path("views/k8s/{$template}.blade.php"));

    expect($source)->toContain('enableServiceLinks: false');
})->with(['base/deployment', 'horizon/deployment', 'queues/deployment', 'reverb/deployment', 'scheduler/cronjob']);
