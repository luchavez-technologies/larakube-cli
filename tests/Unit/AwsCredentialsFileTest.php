<?php

use App\Services\Cloud\AwsCredentialsFile;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('the default profile is replaced and every other profile is kept', function (): void {
    $before = "[work]\naws_access_key_id = WORKKEY\n\n[default]\naws_access_key_id = OLD\naws_secret_access_key = OLDSECRET\n\n[other]\nx = y\n";

    $after = AwsCredentialsFile::upsert($before, 'default', ['aws_access_key_id' => 'NEW', 'aws_secret_access_key' => 'NEWSECRET']);

    expect($after)->toContain("[work]\naws_access_key_id = WORKKEY")
        ->toContain("[default]\naws_access_key_id = NEW\naws_secret_access_key = NEWSECRET")
        ->toContain("[other]\nx = y")
        ->not->toContain('OLD');
});

test('a missing profile is appended, and doing it twice changes nothing', function (): void {
    $once = AwsCredentialsFile::upsert("[work]\na = b\n", 'default', ['region' => 'us-east-1']);

    expect($once)->toBe("[work]\na = b\n\n[default]\nregion = us-east-1\n")
        ->and(AwsCredentialsFile::upsert($once, 'default', ['region' => 'us-east-1']))->toBe($once);
});

test('the files are private to the owner', function (): void {
    $home = TemporaryDirectory::make();

    AwsCredentialsFile::save($home->path(), 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');

    expect(substr(sprintf('%o', fileperms($home->path('.aws/credentials'))), -4))->toBe('0600')
        ->and(substr(sprintf('%o', fileperms($home->path('.aws'))), -4))->toBe('0700')
        ->and(file_get_contents($home->path('.aws/config')))->toContain('region = us-east-1');
});

test('save supports named profiles', function (): void {
    $home = TemporaryDirectory::make();

    AwsCredentialsFile::save($home->path(), 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-west-2', 'client-a');

    expect(file_get_contents($home->path('.aws/credentials')))->toContain('[client-a]')
        ->and(file_get_contents($home->path('.aws/config')))->toContain('[profile client-a]');
});
