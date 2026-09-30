<?php

namespace App\Notifications\Concerns;

use Throwable;

/**
 * The retry policy shared by every notification that sends mail.
 *
 * It lives in one place because all of these notifications carry mail, so all
 * of them fail the same way: a greylisted server, a connection timeout, a rate
 * limit. Those are transient and worth retrying, and a student who is told
 * their work was graded deserves more than one attempt at the email. How many
 * attempts, and how far apart they are, are both decided below.
 *
 * Three attempts, not one, and not ten: past the third the provider is down
 * rather than slow, and the failure lands in `failed_jobs` where it is visible
 * instead of being retried forever in the dark.
 *
 * **Do not add a `$backoff` property here.** It looks like it should work and it
 * silently does not. `SendQueuedNotifications` copies `tries` onto itself from
 * the notification, but it has no `$backoff` property and no `backoff()` method,
 * so `Worker::calculateBackoff()` falls straight through to the worker's
 * `--backoff` option and the notification's own value is never read. Retry
 * spacing for these mails is therefore a property of the worker process, not of
 * the notification, and `NotificationQueueingTest` fails if a `$backoff` is
 * added in the belief that it does something.
 *
 * **Keep the worker's `--backoff` below the queue's `retry_after`.** The database
 * driver reserves a job for `retry_after` seconds and only makes it available
 * again once that window passes. A backoff longer than the window means the job
 * looks free while its previous attempt is still sleeping, so a second worker
 * picks it up and the notification is delivered twice. With the shipped
 * `DB_QUEUE_RETRY_AFTER` of 90 that caps the gap below 90 seconds.
 *
 * **Retry granularity is per channel.** Laravel dispatches one job per channel,
 * so a `database` + `mail` notification is two independently retried jobs that
 * happen to share a notification id. A mail failure therefore never replays the
 * in-app insert. The one path that could duplicate a database row is the
 * database job itself throwing *after* its insert -- narrow, and still the
 * lesser evil: with `mail` first, a flaky provider would cost the student the
 * in-app record as well, so a transient problem would delete the notification
 * instead of repeating it. The database row is the durable record and must not
 * depend on a third party being reachable.
 */
trait RetriesTransientMailFailures
{
    /**
     * Attempts including the first. This one genuinely reaches the worker: the
     * `SendQueuedNotifications` constructor copies it off the notification.
     * Three is enough to ride out a rate limit without letting a genuinely dead
     * provider spin for minutes.
     */
    public int $tries = 3;

    /**
     * Mail is best-effort delivery of a record the student already has in-app,
     * so a terminal failure is reported for visibility and left in
     * `failed_jobs` for an operator. Nothing is raised into the user's face:
     * the work they asked for is already saved and the page they were on has
     * long since returned.
     */
    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
