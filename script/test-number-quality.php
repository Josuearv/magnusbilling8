<?php
/**
 * Standalone unit test suite for NumberQualityService scoring & cross-trunk heuristics.
 *
 * Run on any PHP 8.x environment without DB dependency:
 *   php script/test-number-quality.php
 */

require_once __DIR__ . '/../protected/components/NumberQualityService.php';

function assertTrue($cond, $label)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

// 1. Insufficient sample
$r1 = NumberQualityService::evaluateMetrics([
    'attempts' => 3,
    'answered' => 3,
    'failed' => 0,
    'cnt_short_5s' => 3,
    'sum_duration' => 9,
    'sum_duration_sq' => 27,
]);
assertTrue($r1['quality_label'] === 'INSUFFICIENT_DATA', 'Fewer than 5 attempts -> INSUFFICIENT_DATA');

// 2. Frequent short <=5s calls -> BAD_SHORT_5S
$r2 = NumberQualityService::evaluateMetrics([
    'attempts' => 12,
    'answered' => 10,
    'failed' => 2,
    'cnt_short_5s' => 8,
    'cnt_6_30s' => 2,
    'cnt_mid_short' => 2,
    'cnt_long_180s' => 0,
    'sum_duration' => 38,
    'sum_duration_sq' => 280,
    'fail_no_answer' => 2,
]);
assertTrue($r2['quality_label'] === 'BAD_SHORT_5S', '80% <=5s answers -> BAD_SHORT_5S (got ' . $r2['quality_label'] . ')');
assertTrue($r2['quality_score'] < 45.0, 'BAD_SHORT_5S score below 45');

// 3. Always under 3 minutes (never >=180s, varied duration) -> BAD_UNDER_3MIN
$r3 = NumberQualityService::evaluateMetrics([
    'attempts' => 10,
    'answered' => 8,
    'failed' => 2,
    'cnt_short_5s' => 1,
    'cnt_6_30s' => 3,
    'cnt_31_179s' => 4,
    'cnt_mid_short' => 7,
    'cnt_long_180s' => 0,
    'sum_duration' => 420,
    'sum_duration_sq' => 39000, // stddev ~46s, CV ~0.88 (not rigid)
    'fail_no_answer' => 2,
]);
assertTrue($r3['quality_label'] === 'BAD_UNDER_3MIN', '0 calls >=3m with high mid-short -> BAD_UNDER_3MIN (got ' . $r3['quality_label'] . ')');

// 4. Rigid timer cutoff loop (all ~30s +/- 1s) -> RIGID_CUT_PATTERN
// 8 calls of ~30s: mean=30, sum_sq = 8 * (30^2 + 1^2) = 7208 -> stddev=1.0, CV=0.033
$r4 = NumberQualityService::evaluateMetrics([
    'attempts' => 9,
    'answered' => 8,
    'failed' => 1,
    'cnt_6_30s' => 8,
    'cnt_mid_short' => 8,
    'sum_duration' => 240,
    'sum_duration_sq' => 7208,
    'fail_busy' => 1,
]);
assertTrue($r4['quality_label'] === 'RIGID_CUT_PATTERN', 'Low duration CV < 0.22 -> RIGID_CUT_PATTERN (got ' . $r4['quality_label'] . ')');

// 5. Unreachable / failure dominant -> UNREACHABLE_FAIL_PATTERN
$r5 = NumberQualityService::evaluateMetrics([
    'attempts' => 20,
    'answered' => 1,
    'failed' => 19,
    'cnt_mid_short' => 1,
    'cnt_6_30s' => 1,
    'sum_duration' => 20,
    'sum_duration_sq' => 400,
    'fail_chanunavail' => 15,
    'fail_no_answer' => 4,
]);
assertTrue($r5['quality_label'] === 'UNREACHABLE_FAIL_PATTERN', 'Low ASR + dominant fail -> UNREACHABLE_FAIL_PATTERN');
assertTrue($r5['dominant_fail_cause'] === 'CHANUNAVAIL', 'Dominant fail detected as CHANUNAVAIL');

// 6. Healthy number -> HEALTHY
$r6 = NumberQualityService::evaluateMetrics([
    'attempts' => 15,
    'answered' => 10,
    'failed' => 5,
    'cnt_short_5s' => 0,
    'cnt_6_30s' => 1,
    'cnt_31_179s' => 2,
    'cnt_mid_short' => 3,
    'cnt_long_180s' => 7,
    'sum_duration' => 2600,
    'sum_duration_sq' => 800000,
    'fail_no_answer' => 5,
]);
assertTrue($r6['quality_label'] === 'HEALTHY', 'Strong ASR and >=3m calls -> HEALTHY (got ' . $r6['quality_label'] . ')');
assertTrue($r6['quality_score'] >= 70.0, 'HEALTHY score >= 70');

// 7. Cross-trunk separation -> TRUNK_SUSPECT
$badOnTrunk1  = array_merge($r5, ['canonical_number' => '5511999990000', 'id_trunk' => 1]);
$goodOnTrunk2 = array_merge($r6, ['canonical_number' => '5511999990000', 'id_trunk' => 2]);
$separated    = NumberQualityService::applyCrossTrunkSeparation([$badOnTrunk1, $goodOnTrunk2]);
assertTrue($separated[0]['quality_label'] === 'TRUNK_SUSPECT', 'Bad on trunk #1 when good on trunk #2 -> TRUNK_SUSPECT');
assertTrue((int) $separated[0]['is_trunk_suspect'] === 1, 'is_trunk_suspect flagged = 1');
assertTrue($separated[1]['quality_label'] === 'HEALTHY', 'Healthy trunk remains HEALTHY');

echo "All NumberQuality unit assertions passed.\n";
