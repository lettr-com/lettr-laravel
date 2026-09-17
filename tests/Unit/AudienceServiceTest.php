<?php

use Lettr\Dto\Audience\AudienceTopicSubscription;
use Lettr\Dto\Audience\BulkAudienceContactRow;
use Lettr\Dto\Audience\BulkAudienceContactTopicsData;
use Lettr\Dto\Audience\BulkCreateAudienceContactsData;
use Lettr\Dto\Audience\BulkStoreAudienceContactsResult;
use Lettr\Dto\Audience\BulkSubscribeContactsToTopicsResult;
use Lettr\Dto\Audience\BulkUnsubscribeContactsFromTopicsResult;
use Lettr\Exceptions\ApiException;
use Lettr\Exceptions\ConflictException;
use Lettr\Exceptions\ContactAlreadyExistsException;
use Lettr\Exceptions\InvalidValueException;
use Lettr\Laravel\Facades\Lettr;
use Lettr\Services\Audience\AudienceContactService;
use Lettr\Services\Audience\AudienceListService;
use Lettr\Services\Audience\AudiencePropertyService;
use Lettr\Services\Audience\AudienceSegmentService;
use Lettr\Services\Audience\AudienceTopicService;
use Lettr\Services\AudienceService;

it('resolves the audience service from the facade', function () {
    expect(Lettr::audience())->toBeInstanceOf(AudienceService::class);
});

it('resolves the audience service via the manager magic property', function () {
    expect(app('lettr')->audience)->toBeInstanceOf(AudienceService::class);
});

it('exposes the five audience sub-services', function () {
    $audience = Lettr::audience();

    expect($audience->contacts())->toBeInstanceOf(AudienceContactService::class)
        ->and($audience->lists())->toBeInstanceOf(AudienceListService::class)
        ->and($audience->segments())->toBeInstanceOf(AudienceSegmentService::class)
        ->and($audience->topics())->toBeInstanceOf(AudienceTopicService::class)
        ->and($audience->properties())->toBeInstanceOf(AudiencePropertyService::class);
});

it('exposes the audience sub-services via magic properties', function () {
    $audience = Lettr::audience();

    expect($audience->contacts)->toBeInstanceOf(AudienceContactService::class)
        ->and($audience->lists)->toBeInstanceOf(AudienceListService::class)
        ->and($audience->segments)->toBeInstanceOf(AudienceSegmentService::class)
        ->and($audience->topics)->toBeInstanceOf(AudienceTopicService::class)
        ->and($audience->properties)->toBeInstanceOf(AudiencePropertyService::class);
});

it('exposes the bulk topic subscribe and unsubscribe endpoints on the contact service', function () {
    $contacts = Lettr::audience()->contacts();

    expect(method_exists($contacts, 'bulkSubscribeTopics'))->toBeTrue()
        ->and(method_exists($contacts, 'bulkUnsubscribeTopics'))->toBeTrue();

    $subscribe = new ReflectionMethod($contacts, 'bulkSubscribeTopics');
    $unsubscribe = new ReflectionMethod($contacts, 'bulkUnsubscribeTopics');

    expect((string) $subscribe->getParameters()[0]->getType())->toBe(BulkAudienceContactTopicsData::class)
        ->and((string) $subscribe->getReturnType())->toBe(BulkSubscribeContactsToTopicsResult::class)
        ->and((string) $unsubscribe->getParameters()[0]->getType())->toBe(BulkAudienceContactTopicsData::class)
        ->and((string) $unsubscribe->getReturnType())->toBe(BulkUnsubscribeContactsFromTopicsResult::class);
});

it('builds a bulk topics payload for both subscribe directions', function () {
    $data = new BulkAudienceContactTopicsData(
        contactIds: ['contact-1', 'contact-2'],
        topicIds: ['topic-1'],
    );

    expect($data->toArray())->toBe([
        'contact_ids' => ['contact-1', 'contact-2'],
        'topic_ids' => ['topic-1'],
    ]);
});

it('builds a per-contact bulk create payload with row level opt outs', function () {
    $data = BulkCreateAudienceContactsData::forContacts(
        contacts: [
            new BulkAudienceContactRow(
                email: 'cara@example.com',
                properties: ['plan' => 'pro'],
                listIds: ['list-vip'],
                topics: [AudienceTopicSubscription::optOut('topic-promos')],
            ),
            new BulkAudienceContactRow(email: 'dan@example.com'),
        ],
        listIds: ['list-everyone'],
        topics: [AudienceTopicSubscription::optIn('topic-newsletter')],
        updateExisting: true,
    );

    expect($data->toArray())->toBe([
        'contacts' => [
            [
                'email' => 'cara@example.com',
                'properties' => ['plan' => 'pro'],
                'list_ids' => ['list-vip'],
                'topics' => [['id' => 'topic-promos', 'subscription' => 'opt_out']],
            ],
            ['email' => 'dan@example.com'],
        ],
        'list_ids' => ['list-everyone'],
        'topics' => [['id' => 'topic-newsletter', 'subscription' => 'opt_in']],
        'update_existing' => true,
    ]);
});

it('keeps the legacy flat bulk create payload byte identical', function () {
    $data = new BulkCreateAudienceContactsData(
        emails: ['a@example.com', 'b@example.com'],
        listId: 'list-1',
        properties: ['source' => 'legacy-import'],
    );

    expect($data->toArray())->toBe([
        'emails' => ['a@example.com', 'b@example.com'],
        'list_id' => 'list-1',
        'properties' => ['source' => 'legacy-import'],
    ]);
});

it('rejects a bulk create with neither emails nor contacts', function () {
    new BulkCreateAudienceContactsData;
})->throws(InvalidValueException::class);

it('reports partial success on a bulk create result', function () {
    $result = BulkStoreAudienceContactsResult::from([
        'created' => 1,
        'already_existed' => 1,
        'updated' => 1,
        'error_count' => 1,
        'errors' => [
            ['index' => 2, 'email' => 'nope', 'error_code' => 'invalid_email', 'error' => 'Invalid email.'],
        ],
        'contacts' => [
            ['id' => 'contact-1', 'email' => 'new@example.com', 'created' => true],
            ['id' => 'contact-2', 'email' => 'existing@example.com', 'created' => false],
        ],
    ]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errorCount)->toBe(1)
        ->and($result->updated)->toBe(1)
        ->and($result->contactIds())->toBe(['contact-1', 'contact-2'])
        ->and($result->idFor('existing@example.com'))->toBe('contact-2')
        ->and($result->idFor('absent@example.com'))->toBeNull();
});

it('reads a pre-bulk-rework response without the new fields', function () {
    $result = BulkStoreAudienceContactsResult::from([
        'created' => 2,
        'already_existed' => 1,
    ]);

    expect($result->created)->toBe(2)
        ->and($result->alreadyExisted)->toBe(1)
        ->and($result->updated)->toBe(0)
        ->and($result->hasErrors())->toBeFalse()
        ->and($result->contactIds())->toBe([]);
});

it('keeps the duplicate contact exception catchable by the documented handlers', function () {
    $exception = new ContactAlreadyExistsException(
        'A contact with the email x@example.com already exists.',
        'x@example.com',
    );

    expect($exception)->toBeInstanceOf(ConflictException::class)
        ->and($exception)->toBeInstanceOf(ApiException::class)
        ->and($exception->email)->toBe('x@example.com');
});
