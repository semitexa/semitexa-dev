<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Phpstan;

/**
 * The violations this project has looked at and decided to keep, with the
 * reason attached to each.
 *
 * ## Why this is not a PHPStan baseline
 *
 * A baseline makes a violation invisible. These are the opposite: the rule
 * still fires, the gate still reports it, and the envelope still names it —
 * as ACCEPTED, with the reason, rather than as a failure. A reader learns both
 * that the rule caught something and that somebody already decided about it.
 *
 * ## Why it is a class and not two lists
 *
 * There used to be two. The ratchet test held the accepted sites, and the
 * `phpstan_di` gate knew nothing about them, so anyone who touched one of these
 * files got a red `ai:verify` on a violation the project had already settled.
 * That leaves an agent two bad choices: fix what was deliberately left alone,
 * or learn to ignore a red gate. The second is worse — a gate that is regularly
 * red for an accepted reason stops being a signal at all.
 *
 * Two lists is also how sixteen static-container-access violations accumulated
 * unnoticed in the first place. So there is one list, read by both.
 *
 * ## Adding to it
 *
 * Don't, unless property injection genuinely is not the answer, and say why in
 * the entry. The counts are part of the entry: accepting one occurrence does
 * not accept a second that appears later in the same file.
 */
final class AcceptedViolations
{
    /**
     * Keyed by repository-relative path, then by rule identifier.
     *
     * TWO COUNTS, because two consumers measure different things and one number
     * for both is a hole. `diagnostics` is how many reports from the analyser
     * are accepted; `source_occurrences` is how many textual matches the
     * repository-wide ratchet sees. They differ wherever the pattern also
     * appears in a comment — accept the larger number in the gate and a
     * genuinely new violation in that file slips through unnoticed.
     *
     */
    private const VENDOR_PREFIX = 'vendor/semitexa/';

    /** @var array<string, array<string, array{site: string, diagnostics: int, source_occurrences: int, reason: string}>> */
    private const ACCEPTED = [
        'packages/semitexa-dev/src/Application/Console/Command/AiInvokeCommand.php' => [
            'semitexa.staticContainerAccess' => [
                'site' => 'execute',
                'diagnostics' => 1,
                'source_occurrences' => 1,
                'reason' => 'Builds a NEW request-scoped container rather than reading the current one. '
                    . 'There is nothing to inject: the container it wants does not exist yet. Same tier as '
                    . 'ReplayRunner, which the rule blesses by name.',
            ],
        ],
        'packages/semitexa-ledger/src/Application/Service/CommandProcessor.php' => [
            'semitexa.staticContainerAccess' => [
                'site' => 'handleLocally',
                'diagnostics' => 1,
                'source_occurrences' => 1,
                'reason' => 'Constructed directly rather than by the container, so an injected property is '
                    . 'never filled. Fixing it means changing who builds it, which is not a one-step change.',
            ],
        ],
        'packages/semitexa-ledger/src/Application/Service/LedgerReplayer.php' => [
            'semitexa.staticContainerAccess' => [
                'site' => 'processMessage',
                'diagnostics' => 1,
                'source_occurrences' => 1,
                'reason' => 'Constructed directly rather than by the container, so an injected property is '
                    . 'never filled. Fixing it means changing who builds it, which is not a one-step change.',
            ],
        ],
        'packages/semitexa-ssr/src/Application/Service/Layout/SlotHandlerPipeline.php' => [
            'semitexa.staticContainerAccess' => [
                'site' => 'resolveHandler',
                'diagnostics' => 1,
                'source_occurrences' => 1,
                'reason' => 'Constructed directly rather than by the container, so an injected property is '
                    . 'never filled. Fixing it means changing who builds it, which is not a one-step change.',
            ],
        ],
        'packages/semitexa-orm/src/Query/CollectionQueryCompiler.php' => [
            'semitexa.builtSqlFragment' => [
                'site' => 'applyKeysetPredicate',
                'diagnostics' => 1,
                'source_occurrences' => 1,
                'reason' => 'The ONE composed whereRaw() fragment in the tree, and the reason the rule '
                    . 'reports composition rather than trying to judge the string: keyset pagination builds '
                    . 'an OR of AND-branches whose shape depends on how many sort terms the request carries, '
                    . 'so it cannot be written as a literal. Every identifier in it goes through '
                    . 'SqlIdentifier::quote() (from ORM metadata, never from the request), every value is a '
                    . '? binding, and the only other inserted text is < or > chosen from SortDirection. '
                    . 'Nothing user-supplied reaches the statement text.',
            ],
        ],
        // THE TWO CLASS-LEVEL ENTRIES. `domainModelEncapsulation` reports at the
        // MAPPER's declaration, not inside any method, so until
        // EnclosingSymbol::at() learned to fall back to the enclosing class
        // these could not be written at all — the site never matched and the
        // allowance silently did nothing. See tk-accepted-violations-cannot-
        // express-a-class-level-finding.
        //
        // source_occurrences equals diagnostics here because this rule is
        // semantic: no textual ratchet scans for it, so there is no second
        // number to record. The count still does its job — a twentieth
        // violation in these files is reported.
        'packages/semitexa-webhooks/src/Application/Db/MySQL/Mapper/WebhookInboxMapper.php' => [
            'semitexa.domainModelEncapsulation' => [
                'site' => 'WebhookInboxMapper',
                'diagnostics' => 8,
                'source_occurrences' => 8,
                'reason' => 'InboundDelivery mutates ONLY through transitions that name an intention — '
                    . 'markProcessing(), markProcessed(), markFailed(), markDuplicateIgnored() — and carries '
                    . 'not one plain setter. The rule asks for setStatus(), setFailedAt(), setProcessedAt(); '
                    . 'adding them would let any caller put a delivery into a state no transition allows, '
                    . 'which is the thing the model is shaped to prevent. The rule is right about the '
                    . 'general case and wrong about this one.',
            ],
        ],
        'packages/semitexa-webhooks/src/Application/Db/MySQL/Mapper/WebhookOutboxMapper.php' => [
            'semitexa.domainModelEncapsulation' => [
                'site' => 'WebhookOutboxMapper',
                'diagnostics' => 11,
                'source_occurrences' => 11,
                'reason' => 'OutboundDelivery is the same shape as InboundDelivery: markDelivering(), '
                    . 'markDelivered(), markRetryScheduled(), markCancelled(), resetToPending() — a lease '
                    . 'and a retry schedule that only move together. A setLeaseOwner() beside them would '
                    . 'let a caller take a lease without setting its expiry, which is precisely the state '
                    . 'the transitions exist to make unreachable.',
            ],
        ],
        'packages/semitexa-graphql/src/Application/Service/Runtime/ContainerHandlerInvoker.php' => [
            'semitexa.staticContainerAccess' => [
                // One real call, plus one mention in a docblock that the
                // ratchet's textual scan also counts. MEASURED 2026-09-12:
                // ContainerFactory:: appears on lines 34 (comment) and 110 (code).
                'site' => 'container',
                'diagnostics' => 1,
                'source_occurrences' => 2,
                'reason' => 'Constructed directly rather than by the container, so an injected property is '
                    . 'never filled. Fixing it means changing who builds it, which is not a one-step change.',
            ],
        ],
    ];

    /**
     * @return array<string, array<string, array{site: string, diagnostics: int, source_occurrences: int, reason: string}>>
     */
    public static function all(): array
    {
        return self::ACCEPTED;
    }

    /**
     * How many ANALYSER REPORTS of $rule are accepted in $path. Zero means none.
     */
    public static function allowanceFor(string $path, string $rule): int
    {
        return self::ACCEPTED[self::canonicalise($path)][$rule]['diagnostics'] ?? 0;
    }

    public static function reasonFor(string $path, string $rule): ?string
    {
        return self::ACCEPTED[self::canonicalise($path)][$rule]['reason'] ?? null;
    }

    /**
     * The symbol the accepted violation lives in — the method, or the class
     * when the rule reports on the declaration itself.
     *
     * The file and the rule were the whole key, so removing the blessed call
     * and writing a different one elsewhere in the same class kept the gate
     * green with somebody else's reason attached — and the textual ratchet saw
     * an unchanged count either way. The site is what closes that. Raised in
     * review of dev#83.
     *
     * Not the line, which moves whenever anything above it does, and not the
     * message, which for `staticContainerAccess` names the class and not the
     * method. See {@see EnclosingSymbol}.
     *
     * A CLASS NAME IS A WIDER STATEMENT than a method name, and that is the
     * cost of accepting a class-level finding. It is not the whole class: a
     * method always wins over the class holding it, so a violation INSIDE
     * toDomain() resolves to `toDomain` and is reported as usual. What the
     * class site covers is the part of the class that is in no method — the
     * declaration, its attributes, its properties and constants. The
     * `diagnostics` count holds the rest: the twentieth report is not absorbed
     * by an allowance written for nineteen.
     */
    public static function siteFor(string $path, string $rule): ?string
    {
        return self::ACCEPTED[self::canonicalise($path)][$rule]['site'] ?? null;
    }

    /**
     * The one spelling of a package file, whichever layout it arrived in.
     *
     * The same source has two paths: `packages/semitexa-dev/src/...` in this
     * workspace and `vendor/semitexa/dev/src/...` in a consumer install — and
     * in the workspace BOTH are reachable, since vendor/semitexa/* is a symlink
     * into packages/. Keying only on one meant the same accepted violation was
     * green addressed one way and red addressed the other.
     *
     * Textual, deliberately: realpath resolves the workspace symlink but a
     * consumer has no packages/ directory for it to resolve to, so the layout
     * that most needs this is the one where realpath cannot help.
     */
    public static function canonicalise(string $path): string
    {
        $path = ltrim($path, '/');

        if (!str_starts_with($path, self::VENDOR_PREFIX)) {
            return $path;
        }

        $rest = substr($path, strlen(self::VENDOR_PREFIX));
        $slash = strpos($rest, '/');
        if ($slash === false || $slash === 0) {
            return $path;
        }

        return 'packages/semitexa-' . $rest;
    }

    /**
     * Accepted TEXTUAL occurrence counts for one rule, as path => count — the
     * shape the repository-wide ratchet compares its scan against. Not the same
     * number the gate allows; see the note on ACCEPTED.
     *
     * @return array<string, int>
     */
    public static function countsForRule(string $rule): array
    {
        $out = [];
        foreach (self::ACCEPTED as $path => $rules) {
            if (isset($rules[$rule])) {
                $out[$path] = $rules[$rule]['source_occurrences'];
            }
        }
        ksort($out);

        return $out;
    }
}
