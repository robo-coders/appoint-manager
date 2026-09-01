<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Rebooking\RebookMessenger;
use App\Support\SendWindow;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The daily chase — except it runs hourly.
 *
 * Hourly, because the send window is evaluated in each tenant's own timezone
 * and a job that runs once at 09:00 UTC can only ever be inside one timezone's
 * window. Hourly is only safe because the duplicate rule is a unique index in
 * `rebook_sends` rather than a condition in this file: twenty-four runs a day
 * produce one message per subject per due cycle, and would do so if it ran
 * every minute.
 */
class SendRebookReminders extends Command
{
    protected $signature = 'rebooking:send
        {--tenant= : Restrict to one tenant, by id or slug}
        {--subject=* : Restrict to these subject ids. Requires --tenant}
        {--ignore-window : Send outside the tenant\'s configured hours}
        {--force : Send even though the tenant has not turned messages on. Requires --subject}
        {--dry-run : Print what would be sent and send nothing}';

    protected $description = 'Send due rebooking messages for tenants that have confirmed sending.';

    public function handle(RebookMessenger $messages, TenantContext $context): int
    {
        $subjects = array_values(array_filter(array_map('intval', (array) $this->option('subject'))));

        if ($subjects !== [] && ! $this->option('tenant')) {
            $this->error('--subject requires --tenant. Subject ids are per tenant and sending to the wrong one is not recoverable.');

            return self::FAILURE;
        }

        /*
         * `--force` bypasses the switch the operator has to throw before this
         * feature sends anything. That is defensible for one named subject —
         * putting a real text on a real handset before a customer sees it is the
         * whole point — and indefensible for a client base, so it is refused.
         */
        if ($this->option('force') && $subjects === []) {
            $this->error('--force requires --subject. It exists to send one deliberate test message, not to switch the feature on.');

            return self::FAILURE;
        }

        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->error('No matching tenant.');

            return self::FAILURE;
        }

        $total = 0;

        foreach ($tenants as $tenant) {
            $context->set($tenant);

            if ($this->option('dry-run')) {
                $total += $this->preview($messages, $tenant, $subjects);

                continue;
            }

            $sent = $messages->sendDue(
                $tenant,
                null,
                null,
                $subjects,
                (bool) $this->option('ignore-window'),
                $subjects !== [] && (bool) $this->option('force'),
            );

            $total += $sent;

            if ($sent > 0) {
                $this->info("{$tenant->slug}: {$sent}");
            }
        }

        $context->clear();

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $subjects
     */
    private function preview(RebookMessenger $messages, Tenant $tenant, array $subjects): int
    {
        $run = $messages->dryRun($tenant);
        $rows = $subjects === []
            ? $run['messages']
            : array_values(array_filter($run['messages'], fn (array $row) => in_array((int) $row['subject_id'], $subjects, true)));

        $this->line('');
        $this->line($tenant->name.' ('.$tenant->slug.')');
        $this->line('  sending: '.($messages->isEnabled($tenant) ? 'on' : 'off'));
        $this->line('  window: '.SendWindow::describe($tenant).' '.$tenant->timezone
            .' — '.($run['in_window'] ? 'open now' : 'closed now'));
        $this->line('  due: '.count($rows).', segments: '.array_sum(array_column($rows, 'segments')));
        $this->line('  book: '.$run['book_url']);

        if ($run['book_url_unreachable']) {
            $this->error('  The booking link points at this computer. A phone cannot open it.');
            $this->line('  Set APP_URL_BOOK to a tunnel or this machine\'s LAN address. Leave APP_URL alone.');
        }

        foreach ($rows as $row) {
            $flag = $row['segments'] > 1 ? '  ** '.$row['segments'].' segments ('.$row['encoding'].') — billed as '.$row['segments'] : '';
            $this->line('  #'.$row['subject_id'].' '.$row['phone'].'  '.$row['characters'].' chars'.$flag);
            $this->line('     '.$row['body']);
        }

        foreach ($run['suppressed'] as $row) {
            $this->line('  #'.$row['subject_id'].' skipped: '.$row['reason']);
        }

        return count($rows);
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        $key = $this->option('tenant');

        if ($key === null || $key === '') {
            return Tenant::query()->get();
        }

        return Tenant::query()
            ->where(fn ($query) => $query->where('slug', $key)->orWhere('id', (int) $key))
            ->get();
    }
}
