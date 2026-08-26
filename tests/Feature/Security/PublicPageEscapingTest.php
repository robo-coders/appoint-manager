<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

it('escapes a script tag injected through the business name into JSON-LD', function () {
    $payload = '</script><script>window.__pwned=1</script>';
    $salon = aSalon(['tenant' => ['name' => 'Willow '.$payload]]);

    $response = $this->get(route('public.booking.show', $salon['tenant']->slug))->assertOk();

    expect($response->getContent())->not->toContain('<script>window.__pwned=1</script>');
});

it('escapes a script tag injected through the address fields', function () {
    $salon = aSalon(['tenant' => [
        'city' => '</script><script>window.__city=1</script>',
        'address_line_1' => '</script><script>window.__addr=1</script>',
        'postcode' => '</script><script>window.__pc=1</script>',
        'phone' => '</script><script>window.__tel=1</script>',
    ]]);

    $content = $this->get(route('public.booking.show', $salon['tenant']->slug))->assertOk()->getContent();

    expect($content)->not->toContain('<script>window.__city=1</script>')
        ->and($content)->not->toContain('<script>window.__addr=1</script>')
        ->and($content)->not->toContain('<script>window.__pc=1</script>')
        ->and($content)->not->toContain('<script>window.__tel=1</script>');
});

it('escapes tenant-controlled data on the manage and offer shells too', function () {
    $salon = aSalon(['tenant' => ['name' => 'Bad </script><script>window.__manage=1</script>']]);
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $booking = Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $customer->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'status' => BookingStatus::Confirmed,
        'source' => BookingSource::Online,
    ]);

    expect($this->get('/b/'.$booking->public_token)->getContent())
        ->not->toContain('<script>window.__manage=1</script>');
});

it('does not render the preview page for an unknown token', function () {
    $this->get('/preview/'.Str::uuid())->assertNotFound();
});

it('escapes the business name in the preview shell', function () {
    $tenant = Tenant::factory()->create([
        'name' => 'Prev </script><script>window.__prev=1</script>',
        'booking_page_live' => false,
        'preview_token' => (string) Str::uuid(),
    ]);

    expect($this->get('/preview/'.$tenant->preview_token)->getContent())
        ->not->toContain('<script>window.__prev=1</script>');
});
