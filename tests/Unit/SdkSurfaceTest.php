<?php

/**
 * SDK Surface Tests
 *
 * Guards the parts of the lettr-php surface this package hands to users through
 * the facade — builder chains, filter serialization, DTO shapes and enum
 * semantics — so an SDK upgrade that changes them fails here rather than in a
 * consuming app.
 *
 * These tests used to live in tests/Feature/ReadmeDocTest.php, back when the
 * README documented every one of these examples inline. The README now points
 * at docs.lettr.com instead, so they moved here to keep the coverage without
 * making the doc test claim to check examples that no longer exist.
 */

use Lettr\Builders\EmailBuilder;
use Lettr\Dto\Audience\CreateAudienceContactData;
use Lettr\Dto\Audience\DoubleOptInConfig;
use Lettr\Dto\Audience\ListAudienceContactsFilter;
use Lettr\Dto\Audience\SegmentCondition;
use Lettr\Dto\Audience\SegmentConditionGroup;
use Lettr\Dto\Audience\SegmentConditionsInput;
use Lettr\Dto\Audience\UpdateAudiencePropertyData;
use Lettr\Dto\Audience\UpdateAudienceSegmentData;
use Lettr\Dto\Campaign\ListCampaignEventsFilter;
use Lettr\Dto\Campaign\ListCampaignsFilter;
use Lettr\Dto\Folder\ListFoldersFilter;
use Lettr\Dto\Template\CreateTemplateData;
use Lettr\Dto\Template\ListTemplatesFilter;
use Lettr\Enums\AudienceContactStatus;
use Lettr\Enums\AudiencePropertyType;
use Lettr\Enums\AudienceTopicDefaultSubscription;
use Lettr\Enums\AudienceTopicVisibility;
use Lettr\Enums\CampaignStatus;
use Lettr\Enums\EventType;
use Lettr\Enums\SegmentOperator;
use Lettr\Enums\TemplatePurpose;
use Lettr\Laravel\Facades\Lettr;
use Lettr\Services\FolderService;

// ---------------------------------------------------------------------------
// Email builder
// ---------------------------------------------------------------------------

it('email builder supports cc bcc replyTo and text', function () {
    $email = Lettr::emails()->create()
        ->from('sender@example.com')
        ->to(['recipient@example.com'])
        ->cc(['cc@example.com'])
        ->bcc(['bcc@example.com'])
        ->replyTo('reply@example.com')
        ->subject('Welcome!')
        ->html('<h1>Welcome</h1>')
        ->text('Welcome (plain text fallback)');

    expect($email)->toBeInstanceOf(EmailBuilder::class);
});

it('email builder supports tracking and transactional options', function () {
    $email = Lettr::emails()->create()
        ->from('sender@example.com')
        ->to(['recipient@example.com'])
        ->subject('Newsletter')
        ->html('<p>Content</p>')
        ->transactional()
        ->withClickTracking(true)
        ->withOpenTracking(true);

    expect($email)->toBeInstanceOf(EmailBuilder::class);
});

it('email builder supports metadata substitutionData and tag', function () {
    $email = Lettr::emails()->create()
        ->from('sender@example.com')
        ->to(['recipient@example.com'])
        ->subject('Hello!')
        ->html('<p>Hi</p>')
        ->metadata(['user_id' => '123', 'campaign' => 'welcome'])
        ->substitutionData(['name' => 'John', 'company' => 'Acme'])
        ->tag('welcome');

    expect($email)->toBeInstanceOf(EmailBuilder::class);
});

it('email builder supports all email options including inline css and substitutions', function () {
    $email = Lettr::emails()->create()
        ->from('sender@example.com')
        ->to(['recipient@example.com'])
        ->subject('Newsletter')
        ->html('<p>Content</p>')
        ->withClickTracking(true)
        ->withOpenTracking(true)
        ->transactional(false)
        ->withInlineCss(true)
        ->withSubstitutions(true);

    expect($email)->toBeInstanceOf(EmailBuilder::class);
});

it('email builder supports useTemplate with version and substitution data', function () {
    $email = Lettr::emails()->create()
        ->from('sender@example.com')
        ->to(['recipient@example.com'])
        ->useTemplate('order-confirmation', version: 1)
        ->substitutionData([
            'order_id' => '12345',
            'customer_name' => 'John Doe',
            'items' => [
                ['name' => 'Product A', 'price' => 29.99],
                ['name' => 'Product B', 'price' => 49.99],
            ],
            'total' => 79.98,
        ]);

    expect($email)->toBeInstanceOf(EmailBuilder::class);
    $data = $email->build();
    expect($data->templateSlug)->toBe('order-confirmation');
    expect($data->templateVersion)->toBe(1);
});

// ---------------------------------------------------------------------------
// EventType enum
// ---------------------------------------------------------------------------

it('EventType Delivery has label Delivery', function () {
    $type = EventType::Delivery;

    expect($type->label())->toBe('Delivery');
});

it('EventType Delivery isSuccess returns true', function () {
    $type = EventType::Delivery;

    expect($type->isSuccess())->toBeTrue();
});

it('EventType Delivery isFailure returns false', function () {
    $type = EventType::Delivery;

    expect($type->isFailure())->toBeFalse();
});

it('EventType Delivery isEngagement returns false', function () {
    $type = EventType::Delivery;

    expect($type->isEngagement())->toBeFalse();
});

it('EventType Delivery isUnsubscribe returns false', function () {
    $type = EventType::Delivery;

    expect($type->isUnsubscribe())->toBeFalse();
});

it('EventType Bounce isFailure returns true and isSuccess returns false', function () {
    expect(EventType::Bounce->isFailure())->toBeTrue();
    expect(EventType::Bounce->isSuccess())->toBeFalse();
});

it('EventType Click isEngagement returns true and isSuccess returns false', function () {
    expect(EventType::Click->isEngagement())->toBeTrue();
    expect(EventType::Click->isSuccess())->toBeFalse();
});

it('EventType ListUnsubscribe isUnsubscribe returns true and isSuccess returns false', function () {
    expect(EventType::ListUnsubscribe->isUnsubscribe())->toBeTrue();
    expect(EventType::ListUnsubscribe->isSuccess())->toBeFalse();
});

it('EventType enum contains all documented event type values', function () {
    $documented = [
        'injection', 'delivery', 'bounce', 'delay', 'policy_rejection',
        'out_of_band', 'open', 'initial_open', 'click', 'generation_failure',
        'generation_rejection', 'spam_complaint', 'list_unsubscribe', 'link_unsubscribe',
    ];

    $actual = array_map(fn ($case) => $case->value, EventType::cases());

    foreach ($documented as $value) {
        expect($actual)->toContain($value);
    }
});

// ---------------------------------------------------------------------------
// Audience — contacts
// ---------------------------------------------------------------------------

it('CreateAudienceContactData carries email, list id and properties', function () {
    $data = new CreateAudienceContactData(
        email: 'jane@example.com',
        listId: 'list-uuid',
        properties: ['first_name' => 'Jane', 'plan' => 'pro'],
    );

    expect($data->email)->toBe('jane@example.com')
        ->and($data->listId)->toBe('list-uuid')
        ->and($data->properties)->toBe(['first_name' => 'Jane', 'plan' => 'pro']);
});

it('DoubleOptInConfig keeps the documented confirmation settings', function () {
    $config = new DoubleOptInConfig(
        from: 'hello@example.com',
        subject: 'Confirm your subscription',
        templateSlug: 'email-confirmation',
        redirectUrl: 'https://example.com/confirmed',
        fromName: 'Example',
    );

    expect($config->from)->toBe('hello@example.com')
        ->and($config->subject)->toBe('Confirm your subscription')
        ->and($config->templateSlug)->toBe('email-confirmation')
        ->and($config->redirectUrl)->toBe('https://example.com/confirmed')
        ->and($config->fromName)->toBe('Example');
});

it('ListAudienceContactsFilter builds fluently into query params', function () {
    $filter = ListAudienceContactsFilter::create()
        ->page(1)
        ->perPage(50)
        ->search('jane')
        ->status(AudienceContactStatus::Subscribed)
        ->listId('list-uuid');

    expect($filter->toArray())->toBe([
        'page' => 1,
        'per_page' => 50,
        'search' => 'jane',
        'status' => 'subscribed',
        'list_id' => 'list-uuid',
    ]);
});

// ---------------------------------------------------------------------------
// Audience — segments
// ---------------------------------------------------------------------------

it('SegmentConditionsInput serializes groups and conditions as documented', function () {
    $conditions = new SegmentConditionsInput(groups: [
        new SegmentConditionGroup(conditions: [
            new SegmentCondition('email', SegmentOperator::EndsWith, '@example.com'),
            new SegmentCondition('plan', SegmentOperator::Equals, 'pro'),
        ]),
    ]);

    expect($conditions->toArray())->toBe([
        'groups' => [
            [
                'conditions' => [
                    ['field' => 'email', 'operator' => 'ends_with', 'value' => '@example.com'],
                    ['field' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                ],
            ],
        ],
    ]);
});

it('UpdateAudienceSegmentData builder produces an instance', function () {
    $conditions = new SegmentConditionsInput(groups: [
        new SegmentConditionGroup(conditions: [
            new SegmentCondition('email', SegmentOperator::Contains, '@example.com'),
        ]),
    ]);

    $data = UpdateAudienceSegmentData::empty()
        ->withName('Renamed segment')
        ->withConditions($conditions);

    expect($data)->toBeInstanceOf(UpdateAudienceSegmentData::class);
});

// ---------------------------------------------------------------------------
// Audience — properties
// ---------------------------------------------------------------------------

it('UpdateAudiencePropertyData exposes withFallback and clearFallback', function () {
    expect(UpdateAudiencePropertyData::withFallback('basic'))->toBeInstanceOf(UpdateAudiencePropertyData::class)
        ->and(UpdateAudiencePropertyData::clearFallback())->toBeInstanceOf(UpdateAudiencePropertyData::class);
});

// ---------------------------------------------------------------------------
// Audience — enums
// ---------------------------------------------------------------------------

it('AudienceContactStatus reports labels and email eligibility', function () {
    expect(AudienceContactStatus::Subscribed->label())->toBe('Subscribed')
        ->and(AudienceContactStatus::Subscribed->canReceiveEmails())->toBeTrue()
        ->and(AudienceContactStatus::Unsubscribed->canReceiveEmails())->toBeFalse();
});

it('SegmentOperator reports whether a value is required', function () {
    expect(SegmentOperator::Contains->requiresValue())->toBeTrue()
        ->and(SegmentOperator::IsTrue->requiresValue())->toBeFalse()
        ->and(SegmentOperator::IsFalse->requiresValue())->toBeFalse()
        ->and(SegmentOperator::Contains->label())->toBe('Contains');
});

it('AudiencePropertyType exposes the documented type values', function () {
    expect(AudiencePropertyType::StringType->value)->toBe('string')
        ->and(AudiencePropertyType::NumberType->value)->toBe('number')
        ->and(AudiencePropertyType::BooleanType->value)->toBe('boolean')
        ->and(AudiencePropertyType::DateType->value)->toBe('date')
        ->and(AudiencePropertyType::JsonType->value)->toBe('json');
});

it('AudienceTopicVisibility reports public vs private', function () {
    expect(AudienceTopicVisibility::PublicVisibility->isPublic())->toBeTrue()
        ->and(AudienceTopicVisibility::PrivateVisibility->isPublic())->toBeFalse()
        ->and(AudienceTopicVisibility::PublicVisibility->value)->toBe('public');
});

it('AudienceTopicDefaultSubscription reports opt-in semantics', function () {
    expect(AudienceTopicDefaultSubscription::OptIn->isOptIn())->toBeTrue()
        ->and(AudienceTopicDefaultSubscription::OptIn->subscribesNewContactsByDefault())->toBeFalse()
        ->and(AudienceTopicDefaultSubscription::OptOut->subscribesNewContactsByDefault())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Campaigns — filters and enums
// ---------------------------------------------------------------------------

it('ListCampaignsFilter builds fluently into query params', function () {
    $filter = ListCampaignsFilter::create()
        ->status(CampaignStatus::Sent)
        ->page(2)
        ->perPage(50);

    expect($filter->toArray())->toBe([
        'page' => 2,
        'per_page' => 50,
        'status' => 'sent',
    ]);
});

it('ListCampaignEventsFilter omits null cursor and serializes event_type / dates', function () {
    $filter = ListCampaignEventsFilter::create()
        ->eventType(EventType::Click)
        ->startDate(new DateTimeImmutable('2026-05-01T00:00:00+00:00'))
        ->cursor(null);

    expect($filter->toArray())->toBe([
        'event_type' => 'click',
        'start_date' => '2026-05-01T00:00:00+00:00',
    ]);
});

it('CampaignStatus exposes the documented lifecycle values and labels', function () {
    expect(CampaignStatus::Draft->value)->toBe('draft')
        ->and(CampaignStatus::Scheduled->value)->toBe('scheduled')
        ->and(CampaignStatus::Sending->value)->toBe('sending')
        ->and(CampaignStatus::Sent->value)->toBe('sent')
        ->and(CampaignStatus::Failed->value)->toBe('failed')
        ->and(CampaignStatus::InReview->label())->toBe('In Review');
});

// ---------------------------------------------------------------------------
// Templates — purpose
// ---------------------------------------------------------------------------

it('TemplatePurpose exposes the two modules', function () {
    expect(TemplatePurpose::Transactional->value)->toBe('transactional')
        ->and(TemplatePurpose::Campaign->value)->toBe('campaign')
        ->and(TemplatePurpose::Campaign->label())->toBe('Campaign');
});

it('CreateTemplateData emits the purpose only when set', function () {
    $marketing = new CreateTemplateData(
        name: 'October Newsletter',
        html: '<html><body>Hi</body></html>',
        purpose: TemplatePurpose::Campaign,
    );

    $transactional = new CreateTemplateData(
        name: 'Welcome Email',
        html: '<html><body>Hi</body></html>',
    );

    expect($marketing->toArray())->toBe([
        'name' => 'October Newsletter',
        'html' => '<html><body>Hi</body></html>',
        'purpose' => 'campaign',
    ])
        ->and($transactional->toArray())->toBe([
            'name' => 'Welcome Email',
            'html' => '<html><body>Hi</body></html>',
        ])
        ->and($transactional->toArray())->not->toHaveKey('purpose');
});

it('ListTemplatesFilter serializes the purpose filter', function () {
    $filter = ListTemplatesFilter::create()
        ->projectId(5)
        ->purpose(TemplatePurpose::Campaign);

    expect($filter->toArray())->toBe([
        'project_id' => 5,
        'purpose' => 'campaign',
    ]);
});

// ---------------------------------------------------------------------------
// Folders
// ---------------------------------------------------------------------------

it('ListFoldersFilter builds fluently into query params', function () {
    $filter = ListFoldersFilter::create()
        ->projectId(5)
        ->purpose(TemplatePurpose::Campaign)
        ->perPage(50)
        ->page(2);

    expect($filter->toArray())->toBe([
        'project_id' => 5,
        'purpose' => 'campaign',
        'per_page' => 50,
        'page' => 2,
    ]);
});

it('ListFoldersFilter sends nothing when empty', function () {
    expect(ListFoldersFilter::create()->hasFilters())->toBeFalse()
        ->and(ListFoldersFilter::create()->toArray())->toBe([]);
});

it('the facade exposes the folder service', function () {
    expect(Lettr::folders())->toBeInstanceOf(FolderService::class);
});
