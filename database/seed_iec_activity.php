<?php

declare(strict_types=1);

/**
 * Create + publish the IEC Activity Survey (AGRS) form.
 *
 * Captures IEC/awareness activities (Nukkad Natak, Hoardings, Success
 * Stories, Audio/Video dissemination, Wall Paintings, Pamphlets, FPS contact
 * display, Others) with per-activity conditional status fields and
 * sub-categories driven by the IF/THEN condition engine:
 *   - the activity's Status dropdown only appears once its Activity Type is
 *     selected and is then required;
 *   - "Size of hoarding / billboard" appears only for Hoardings / Billboards
 *     with status Done (two AND-ed show conditions);
 *   - "Custom Size" reveals free-text size details;
 *   - Success Stories / Audio / Video sub-category dropdowns appear only when
 *     their activity's status is positive.
 *
 * Idempotent — safe to run repeatedly.
 * Usage: php database/seed_iec_activity.php
 */

require __DIR__ . '/../common/bootstrap.php';

use App\Database\Connection;
use App\Services\SurveyService;

$pdo = Connection::instance();
$svc = new SurveyService();

$existing = (int) $pdo->query("SELECT id FROM survey_forms WHERE code = 'IEC_ACTIVITY_SURVEY'")->fetchColumn();
if ($existing > 0) {
    echo "Survey form IEC_ACTIVITY_SURVEY already exists (id={$existing}); skipping creation." . PHP_EOL;
    exit(0);
}

$formId = $svc->createForm(1, [
    'code' => 'IEC_ACTIVITY_SURVEY',
    'title' => 'IEC Activity Survey (AGRS)',
    'description' => 'Field monitoring of IEC activities under AGRS: street plays, hoardings/billboards, success stories, audio/video dissemination, wall paintings/posters, pamphlet distribution and FPS contact-information display, with geo-tagged photo evidence.',
]);
$versionId = $svc->createVersion($formId, 1, 'initial');
$svc->saveStructure($formId, $versionId, [

    // ---------- Section 1: Survey Details ----------
    ['title' => 'Section 1: Survey Details', 'fields' => [
        ['field_key' => 'survey_id', 'label' => 'Survey ID', 'type' => 'auto_number', 'mandatory' => 1,
            'help_text' => 'Auto-generated unique survey ID (JH/district/date/count/time).'],
        ['field_key' => 'survey_date', 'label' => 'Survey Date', 'type' => 'date', 'mandatory' => 1],
        ['field_key' => 'location', 'label' => 'Location (District → Block → Panchayat → Village)', 'type' => 'location_cascade', 'mandatory' => 1,
            'settings' => ['levels' => ['district', 'block', 'panchayat', 'village']]],
        ['field_key' => 'place_name', 'label' => 'Place Name', 'type' => 'textbox', 'mandatory' => 1],
    ]],

    // ---------- Section 2: Activity Details ----------
    ['title' => 'Section 2: Activity Details', 'fields' => [
        ['field_key' => 'activity_type', 'label' => 'Activity Type', 'type' => 'dropdown', 'mandatory' => 1,
            'options' => [
                ['label' => 'Nukkad Natak', 'value' => 'nukkad_natak'],
                ['label' => 'Hoardings / Billboards', 'value' => 'hoardings_billboards'],
                ['label' => 'Success Stories', 'value' => 'success_stories'],
                ['label' => 'Audio Ad Dissemination', 'value' => 'audio_ad_dissemination'],
                ['label' => 'Video Dissemination', 'value' => 'video_dissemination'],
                ['label' => 'Wall Paintings / Posters', 'value' => 'wall_paintings_posters'],
                ['label' => 'Pamphlet / Leaflet Distribution', 'value' => 'pamphlet_leaflet_distribution'],
                ['label' => 'Display of AGRS Contact Information at FPS', 'value' => 'fps_agrs_contact_display'],
                ['label' => 'Others', 'value' => 'others'],
            ]],

        // Per-activity Status dropdowns — visible + required only when the
        // matching Activity Type is selected.
        ['field_key' => 'nn_status', 'label' => 'Activity Status (Nukkad Natak)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Performance Held', 'value' => 'performance_held'],
                ['label' => 'Performance Not Held', 'value' => 'performance_not_held'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'nukkad_natak', 'action' => 'show'],
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'nukkad_natak', 'action' => 'required'],
            ]],
        ['field_key' => 'hb_status', 'label' => 'Activity Status (Hoardings / Billboards)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Done', 'value' => 'done'],
                ['label' => 'Not Done', 'value' => 'not_done'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'hoardings_billboards', 'action' => 'show'],
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'hoardings_billboards', 'action' => 'required'],
            ]],
        ['field_key' => 'ss_status', 'label' => 'Activity Status (Success Stories)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Published', 'value' => 'published'],
                ['label' => 'Not Published', 'value' => 'not_published'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'success_stories', 'action' => 'show'],
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'success_stories', 'action' => 'required'],
            ]],
        ['field_key' => 'aa_status', 'label' => 'Activity Status (Audio Ad Dissemination)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Done', 'value' => 'done'],
                ['label' => 'Not Done', 'value' => 'not_done'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'audio_ad_dissemination', 'action' => 'show'],
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'audio_ad_dissemination', 'action' => 'required'],
            ]],
        ['field_key' => 'vd_status', 'label' => 'Activity Status (Video Dissemination)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Aired', 'value' => 'aired'],
                ['label' => 'Not Aired', 'value' => 'not_aired'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'video_dissemination', 'action' => 'show'],
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'video_dissemination', 'action' => 'required'],
            ]],
        ['field_key' => 'wp_status', 'label' => 'Activity Status (Wall Paintings / Posters)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Painted or Distributed', 'value' => 'painted_or_distributed'],
                ['label' => 'Not Painted or Distributed', 'value' => 'not_painted_or_distributed'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'wall_paintings_posters', 'action' => 'show'],
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'wall_paintings_posters', 'action' => 'required'],
            ]],
        ['field_key' => 'pl_status', 'label' => 'Activity Status (Pamphlet / Leaflet Distribution)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Done', 'value' => 'done'],
                ['label' => 'Not Done', 'value' => 'not_done'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'pamphlet_leaflet_distribution', 'action' => 'show'],
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'pamphlet_leaflet_distribution', 'action' => 'required'],
            ]],
        ['field_key' => 'fps_status', 'label' => 'Activity Status (AGRS Contact at FPS)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Done', 'value' => 'done'],
                ['label' => 'Not Done', 'value' => 'not_done'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'fps_agrs_contact_display', 'action' => 'show'],
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'fps_agrs_contact_display', 'action' => 'required'],
            ]],
        ['field_key' => 'ot_status', 'label' => 'Activity Status (Others)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Done', 'value' => 'done'],
                ['label' => 'Not Done', 'value' => 'not_done'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'others', 'action' => 'show'],
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'others', 'action' => 'required'],
            ]],

        // Sub-categories / dependent details.
        // Size of hoarding/billboard shows only for Hoardings with status Done
        // (both show conditions must match — AND semantics).
        ['field_key' => 'hb_size', 'label' => 'Size of Hoarding / Billboard', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Small', 'value' => 'small'],
                ['label' => 'Medium', 'value' => 'medium'],
                ['label' => 'Large', 'value' => 'large'],
                ['label' => 'Custom Size', 'value' => 'custom_size'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'hoardings_billboards', 'action' => 'show'],
                ['target_field_key' => 'hb_status', 'operator' => 'equals', 'condition_value' => 'done', 'action' => 'show'],
            ]],
        ['field_key' => 'hb_size_details', 'label' => 'Hoarding Size Details', 'type' => 'textbox',
            'help_text' => 'Enter the custom size (e.g. 20ft x 10ft).',
            'conditions' => [
                ['target_field_key' => 'hb_size', 'operator' => 'equals', 'condition_value' => 'custom_size', 'action' => 'show'],
            ]],
        ['field_key' => 'ss_subcategory', 'label' => 'Sub Category (Success Stories)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Newspaper Stories', 'value' => 'newspaper_stories'],
                ['label' => 'TV / News Agency Stories', 'value' => 'tv_news_agency_stories'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'success_stories', 'action' => 'show'],
                ['target_field_key' => 'ss_status', 'operator' => 'equals', 'condition_value' => 'published', 'action' => 'show'],
            ]],
        ['field_key' => 'aa_subcategory', 'label' => 'Sub Category (Audio Ad Dissemination)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Jagrukta Rath', 'value' => 'jagrukta_rath'],
                ['label' => 'Radio Ads', 'value' => 'radio_ads'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'audio_ad_dissemination', 'action' => 'show'],
                ['target_field_key' => 'aa_status', 'operator' => 'equals', 'condition_value' => 'done', 'action' => 'show'],
            ]],
        ['field_key' => 'vd_subcategory', 'label' => 'Sub Category (Video Dissemination)', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Nagar Nigam LED Screens', 'value' => 'nagar_nigam_led_screens'],
                ['label' => 'Jagrukta Raths', 'value' => 'jagrukta_raths'],
                ['label' => 'Government Functions', 'value' => 'government_functions'],
                ['label' => 'Screenings', 'value' => 'screenings'],
            ],
            'conditions' => [
                ['target_field_key' => 'activity_type', 'operator' => 'equals', 'condition_value' => 'video_dissemination', 'action' => 'show'],
                ['target_field_key' => 'vd_status', 'operator' => 'equals', 'condition_value' => 'aired', 'action' => 'show'],
            ]],
    ]],

    // ---------- Section 3: Evidence & Remarks ----------
    ['title' => 'Section 3: Evidence & Remarks', 'fields' => [
        ['field_key' => 'target_population', 'label' => 'Target Population Covered', 'type' => 'number'],
        ['field_key' => 'photo_1', 'label' => 'Photo 1', 'type' => 'camera'],
        ['field_key' => 'photo_2', 'label' => 'Photo 2', 'type' => 'camera'],
        ['field_key' => 'photo_3', 'label' => 'Photo 3', 'type' => 'camera'],
        ['field_key' => 'geo_location', 'label' => 'Geo Location (Latitude / Longitude)', 'type' => 'gps', 'mandatory' => 1],
        ['field_key' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
    ]],
]);

$svc->publish($formId, 1, 'IEC Activity Survey v1');
echo "Survey form IEC_ACTIVITY_SURVEY created & published (id={$formId})." . PHP_EOL;

// Grant access to the new form for admin + demo users (state admin is implicit).
$pdo->prepare('INSERT IGNORE INTO user_form_access (user_id, form_id, granted_by) VALUES (:u, :f, 1)')
    ->execute(['u' => 1, 'f' => $formId]);
foreach ($pdo->query("SELECT id FROM users WHERE username IN ('dh_surveyor','rk_surveyor','jb_block','sk_district')")->fetchAll() as $u) {
    $pdo->prepare('INSERT IGNORE INTO user_form_access (user_id, form_id, granted_by) VALUES (:u, :f, 1)')
        ->execute(['u' => $u['id'], 'f' => $formId]);
}
echo "Form access granted to demo users." . PHP_EOL;
