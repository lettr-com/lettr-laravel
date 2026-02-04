<?php

use Illuminate\Filesystem\Filesystem;
use Lettr\Dto\Template\MergeTag;
use Lettr\Dto\Template\MergeTagChild;
use Lettr\Laravel\Support\DtoGenerator;

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->generator = new DtoGenerator($this->filesystem);

    config()->set('lettr.templates.dto_path', base_path('app/Dto/Lettr'));
    config()->set('lettr.templates.dto_namespace', 'App\\Dto\\Lettr');
});

it('generates dto that implements Arrayable', function () {
    $mergeTags = [
        new MergeTag(key: 'user_name', required: true, type: 'string'),
    ];

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->once()
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            return str_contains($content, 'use Illuminate\Contracts\Support\Arrayable;')
                && str_contains($content, 'implements Arrayable');
        });

    $result = $this->generator->generate('test-template', $mergeTags);

    expect($result)->not->toBeNull()
        ->and($result['class'])->toBe('App\\Dto\\Lettr\\TestTemplateData');
});

it('generates imports for nested dto classes', function () {
    $mergeTags = [
        new MergeTag(
            key: 'items',
            required: true,
            type: 'array',
            children: [
                new MergeTagChild(key: 'name', type: 'string'),
            ]
        ),
    ];

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $writtenFiles = [];
    $this->filesystem
        ->shouldReceive('put')
        ->twice()
        ->withArgs(function ($path, $content) use (&$writtenFiles) {
            $writtenFiles[$path] = $content;

            return true;
        });

    $this->generator->generate('order-list', $mergeTags);

    // Find the main DTO by checking the path doesn't contain nested class name
    $mainPath = collect($writtenFiles)->keys()->first(fn ($path) => str_contains($path, 'OrderListData.php') && ! str_contains($path, 'ItemData'));
    expect($mainPath)->not->toBeNull();

    $mainContent = $writtenFiles[$mainPath];
    expect($mainContent)
        ->toContain('use App\Dto\Lettr\OrderListDataItemData;')
        ->toContain('use Illuminate\Contracts\Support\Arrayable;');
});

it('generates dto with multiple nested imports', function () {
    $mergeTags = [
        new MergeTag(
            key: 'orders',
            required: true,
            type: 'array',
            children: [
                new MergeTagChild(key: 'id', type: 'integer'),
            ]
        ),
        new MergeTag(
            key: 'products',
            required: true,
            type: 'array',
            children: [
                new MergeTagChild(key: 'name', type: 'string'),
            ]
        ),
    ];

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $writtenFiles = [];
    $this->filesystem
        ->shouldReceive('put')
        ->times(3) // Main DTO + 2 nested DTOs
        ->withArgs(function ($path, $content) use (&$writtenFiles) {
            $writtenFiles[$path] = $content;

            return true;
        });

    $this->generator->generate('multi-nested', $mergeTags);

    // Find the main DTO by path - should be exactly MultiNestedData.php
    $mainPath = collect($writtenFiles)->keys()->first(fn ($path) => str_ends_with($path, 'MultiNestedData.php'));
    expect($mainPath)->not->toBeNull();

    $mainContent = $writtenFiles[$mainPath];
    expect($mainContent)
        ->toContain('use App\Dto\Lettr\MultiNestedDataOrderData;')
        ->toContain('use App\Dto\Lettr\MultiNestedDataProductData;')
        ->toContain('use Illuminate\Contracts\Support\Arrayable;');
});

it('does not generate imports when there are no nested dtos', function () {
    $mergeTags = [
        new MergeTag(key: 'simple_field', required: true, type: 'string'),
        new MergeTag(key: 'another_field', required: false, type: 'integer'),
    ];

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->once()
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            // Should still have Arrayable import from stub
            $hasArrayableImport = str_contains($content, 'use Illuminate\Contracts\Support\Arrayable;');

            // Should not have any nested DTO imports (App\Dto\Lettr\...Data imports)
            $lines = explode("\n", $content);
            $nestedImports = array_filter($lines, function ($line) {
                return str_contains($line, 'use App\Dto\Lettr\\')
                    && str_contains($line, 'Data;')
                    && ! str_contains($line, 'Arrayable');
            });

            return $hasArrayableImport && count($nestedImports) === 0;
        });

    $this->generator->generate('simple-template', $mergeTags);
});

it('returns null when no merge tags provided', function () {
    $result = $this->generator->generate('empty-template', []);

    expect($result)->toBeNull();
});

it('generates correct fully qualified class name', function () {
    $className = $this->generator->getFullyQualifiedDtoClassName('user-profile');

    expect($className)->toBe('App\\Dto\\Lettr\\UserProfileData');
});

it('converts slug to proper class name', function () {
    $className = $this->generator->getDtoClassName('welcome-email-template');

    expect($className)->toBe('WelcomeEmailTemplateData');
});

it('supports dry run mode without writing files', function () {
    $mergeTags = [
        new MergeTag(key: 'name', required: true, type: 'string'),
    ];

    $this->filesystem
        ->shouldReceive('get')
        ->once()
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    // Should not write any files
    $this->filesystem->shouldNotReceive('put');
    $this->filesystem->shouldNotReceive('makeDirectory');

    $result = $this->generator->generate('dry-run-test', $mergeTags, true);

    expect($result)->not->toBeNull()
        ->and($result['class'])->toBe('App\\Dto\\Lettr\\DryRunTestData');
});
