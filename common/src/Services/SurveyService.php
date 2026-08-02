<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;
use RuntimeException;

/**
 * Survey form builder + versioning service.
 */
final class SurveyService
{
    /** @var list<string> */
    public const FIELD_TYPES = [
        'textbox', 'textarea', 'number', 'decimal', 'date', 'time',
        'dropdown', 'radio', 'checkbox', 'multi_select', 'master', 'location_cascade', 'gps', 'camera',
        'signature', 'barcode', 'qr_code', 'file_upload', 'heading', 'auto_number',
    ];

    public function createForm(int $userId, array $data): int
    {
        $pdo = Connection::instance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO survey_forms (code, title, description, category_id, current_version, status, created_by)
                 VALUES (:code, :title, :description, :category_id, 1, "draft", :user)'
            );
            $stmt->execute([
                'code' => $data['code'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'user' => $userId,
            ]);
            $formId = (int) $pdo->lastInsertId();

            $this->createVersion($formId, $userId, 'Initial draft');
            $pdo->commit();
            return $formId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function createVersion(int $formId, int $userId, ?string $note = null): int
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version),0)+1 FROM survey_versions WHERE form_id = :f');
        $stmt->execute(['f' => $formId]);
        $nextVersion = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO survey_versions (form_id, version, status, change_note, published_by)
             VALUES (:form_id, :version, "draft", :note, :user)'
        );
        $stmt->execute(['form_id' => $formId, 'version' => $nextVersion, 'note' => $note, 'user' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Resolve the draft version to edit. Resumes an existing non-empty draft,
     * otherwise creates a new one cloned from the latest published structure so
     * admins edit a working copy of the live form rather than a blank page.
     */
    public function draftForEditing(int $formId, int $userId): int
    {
        $pdo = Connection::instance();

        // Latest published version (id) if any.
        $stmt = $pdo->prepare('SELECT id FROM survey_versions WHERE form_id = :f AND status = "published" ORDER BY version DESC LIMIT 1');
        $stmt->execute(['f' => $formId]);
        $publishedId = (int) ($stmt->fetchColumn() ?: 0);

        // Resume the latest draft only if it actually has structure.
        $stmt = $pdo->prepare('SELECT id FROM survey_versions WHERE form_id = :f AND status = "draft" ORDER BY version DESC LIMIT 1');
        $stmt->execute(['f' => $formId]);
        $draftId = (int) ($stmt->fetchColumn() ?: 0);
        if ($draftId > 0 && $this->hasStructure($draftId)) {
            return $draftId;
        }

        $draftId = $this->createVersion($formId, $userId, 'New edit draft');
        if ($publishedId > 0) {
            $def = $this->formDefinition($formId, $publishedId);
            if ($def !== null) {
                $this->saveStructure($formId, $draftId, $def['sections']);
            }
        }
        return $draftId;
    }

    /** True if a version has at least one section. */
    private function hasStructure(int $versionId): bool
    {
        $stmt = Connection::instance()->prepare('SELECT COUNT(*) FROM survey_sections WHERE form_version_id = :v');
        $stmt->execute(['v' => $versionId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Latest version numbers by status for a form (published + pending draft).
     * @return array{published_version:int, draft_version:int, pending_changes:bool}
     */
    public function versionInfo(int $formId): array
    {
        $pdo = Connection::instance();
        $rows = $pdo->prepare('SELECT status, version FROM survey_versions WHERE form_id = :f ORDER BY version DESC');
        $rows->execute(['f' => $formId]);
        $published = 0;
        $draft = 0;
        foreach ($rows->fetchAll() as $r) {
            if ($r['status'] === 'published' && $published === 0) {
                $published = (int) $r['version'];
            }
            if ($r['status'] === 'draft' && $r['version'] > $draft) {
                $draft = (int) $r['version'];
            }
        }
        return [
            'published_version' => $published,
            'draft_version'     => $draft,
            'pending_changes'   => $draft > $published,
        ];
    }

    public function publish(int $formId, int $userId, ?string $note = null): int
    {
        $pdo = Connection::instance();
        $pdo->beginTransaction();
        try {
            // Find current draft version (or create one).
            $stmt = $pdo->prepare(
                'SELECT id FROM survey_versions WHERE form_id = :f AND status = "draft" ORDER BY version DESC LIMIT 1'
            );
            $stmt->execute(['f' => $formId]);
            $versionId = (int) ($stmt->fetchColumn() ?: 0);

            if ($versionId === 0) {
                $versionId = $this->createVersion($formId, $userId, $note);
            }

            // Ensure no active published version exists to reuse.
            $stmt = $pdo->prepare(
                'SELECT version FROM survey_versions WHERE form_id = :f AND status = "published" ORDER BY version DESC LIMIT 1'
            );
            $stmt->execute(['f' => $formId]);
            $lastPublished = (int) ($stmt->fetchColumn() ?: 0);

            if ($lastPublished > 0) {
                $pdo->prepare('UPDATE survey_versions SET status = "superseded" WHERE form_id = :f AND status = "published"')
                    ->execute(['f' => $formId]);
            }

            $pdo->prepare('UPDATE survey_versions SET status = "published", published_at = NOW(), published_by = :u, change_note = :n WHERE id = :id')
                ->execute(['u' => $userId, 'n' => $note, 'id' => $versionId]);

            $pdo->prepare('UPDATE survey_forms SET status = "published", current_version = (SELECT version FROM survey_versions WHERE id = :vid) WHERE id = :f')
                ->execute(['vid' => $versionId, 'f' => $formId]);

            $pdo->commit();
            return $versionId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Get full form definition (sections/fields/options/validations/conditions) for a version. */
    public function formDefinition(int $formId, ?int $versionId = null): ?array
    {
        $pdo = Connection::instance();

        $stmt = $pdo->prepare(
            'SELECT * FROM survey_forms WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $formId]);
        $form = $stmt->fetch();
        if ($form === false) {
            return null;
        }

        if ($versionId === null) {
            // Prefer the published version; fall back to the latest draft so
            // draft forms can be previewed too.
            $stmt = $pdo->prepare(
                'SELECT id FROM survey_versions WHERE form_id = :f AND status = "published" ORDER BY version DESC LIMIT 1'
            );
            $stmt->execute(['f' => $formId]);
            $versionId = (int) ($stmt->fetchColumn() ?: 0);
            if ($versionId === 0) {
                $stmt = $pdo->prepare(
                    'SELECT id FROM survey_versions WHERE form_id = :f AND status = "draft" ORDER BY version DESC LIMIT 1'
                );
                $stmt->execute(['f' => $formId]);
                $versionId = (int) ($stmt->fetchColumn() ?: 0);
            }
        }

        $sections = $this->sections((int) $versionId);
        return [
            'form'    => $form,
            'version' => $versionId,
            'sections'=> $sections,
        ];
    }

    public function sections(int $versionId): array
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare(
            'SELECT * FROM survey_sections WHERE form_version_id = :v ORDER BY sort_order, id'
        );
        $stmt->execute(['v' => $versionId]);
        $sections = $stmt->fetchAll();

        foreach ($sections as $i => $section) {
            $sections[$i]['fields'] = $this->fields((int) $section['id']);
        }

        // Resolve each condition's target field to its field_key so the UI and
        // mobile client can match it against submitted answers.
        $idToKey = [];
        foreach ($sections as $section) {
            foreach ($section['fields'] as $field) {
                $idToKey[(int) $field['id']] = (string) $field['field_key'];
            }
        }
        foreach ($sections as &$section) {
            foreach ($section['fields'] as &$field) {
                foreach ($field['conditions'] as &$cond) {
                    $cond['target_field_key'] = $idToKey[(int) ($cond['target_field_id'] ?? 0)] ?? null;
                }
                unset($cond);
            }
            unset($field);
        }
        unset($section);

        return $sections;
    }

    public function fields(int $sectionId): array
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare(
            'SELECT * FROM survey_fields WHERE section_id = :s ORDER BY sort_order, id'
        );
        $stmt->execute(['s' => $sectionId]);
        $fields = $stmt->fetchAll();

        foreach ($fields as $i => $field) {
            $fid = (int) $field['id'];
            $fields[$i]['options'] = $pdo->prepare('SELECT option_label, option_value FROM survey_field_options WHERE field_id = ? ORDER BY sort_order');
            $fields[$i]['options']->execute([$fid]);
            $fields[$i]['options'] = $fields[$i]['options']->fetchAll();

            $fields[$i]['validations'] = $pdo->prepare('SELECT rule, rule_value, error_message FROM survey_field_validations WHERE field_id = ?');
            $fields[$i]['validations']->execute([$fid]);
            $fields[$i]['validations'] = $fields[$i]['validations']->fetchAll();

            $fields[$i]['conditions'] = $pdo->prepare(
                'SELECT target_field_id, operator, condition_value, action FROM survey_conditions WHERE field_id = ? ORDER BY sort_order'
            );
            $fields[$i]['conditions']->execute([$fid]);
            $fields[$i]['conditions'] = $fields[$i]['conditions']->fetchAll();

            if (isset($field['settings_json'])) {
                $fields[$i]['settings'] = json_decode((string) $field['settings_json'], true);
            }

            // Master-data fields: resolve items from the linked master group.
            if ($field['type'] === 'master') {
                $settings = $fields[$i]['settings'] ?? [];
                $groupId = (int) ($settings['master_group_id'] ?? 0);
                $fields[$i]['master_group_id'] = $groupId > 0 ? $groupId : null;
                $fields[$i]['master_group_name'] = null;
                if ($groupId > 0) {
                    $stmt = $pdo->prepare('SELECT name FROM master_groups WHERE id = :g');
                    $stmt->execute(['g' => $groupId]);
                    $fields[$i]['master_group_name'] = $stmt->fetchColumn() ?: null;

                    $stmt = $pdo->prepare(
                        'SELECT id, name FROM master_items WHERE group_id = :g AND is_active = 1 ORDER BY sort_order, name'
                    );
                    $stmt->execute(['g' => $groupId]);
                    $items = $stmt->fetchAll();
                    $fields[$i]['options'] = array_map(
                        static fn (array $m) => ['option_label' => $m['name'], 'option_value' => (string) $m['id']],
                        $items
                    );
                }
            }
        }
        return $fields;
    }

    /**
     * Create/update a form version's structure from builder payload.
     * Structure: [{title, description, fields: [{label, field_key, type, mandatory, options, validations}]}]
     */
    public function saveStructure(int $formId, int $versionId, array $sections): void
    {
        $pdo = Connection::instance();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM survey_sections WHERE form_version_id = :v')->execute(['v' => $versionId]);

            $stmt = $pdo->prepare(
                'INSERT INTO survey_sections (form_version_id, title, description, is_heading, sort_order) VALUES (:v, :t, :d, 0, :so)'
            );
            $fieldStmt = $pdo->prepare(
                'INSERT INTO survey_fields
                    (section_id, field_key, label, type, is_mandatory, placeholder, default_value, help_text, show_in_table, allow_multiple, sort_order, settings_json)
                 VALUES (:s, :k, :l, :t, :m, :p, :dv, :h, :st, :am, :so, :sj)'
            );
            $optStmt = $pdo->prepare(
                'INSERT INTO survey_field_options (field_id, option_label, option_value, sort_order, is_default) VALUES (:f, :l, :v, :so, :d)'
            );
            $valStmt = $pdo->prepare(
                'INSERT INTO survey_field_validations (field_id, rule, rule_value, error_message) VALUES (:f, :r, :rv, :em)'
            );
            $condStmt = $pdo->prepare(
                'INSERT INTO survey_conditions (field_id, target_field_id, operator, condition_value, action, sort_order) VALUES (:f, :t, :op, :cv, :a, :so)'
            );

            $fieldIds = [];       // field_key => field id
            $pendingConditions = [];

            foreach ($sections as $so => $section) {
                $stmt->execute([
                    'v' => $versionId,
                    't' => $section['title'] ?? 'Untitled section',
                    'd' => $section['description'] ?? null,
                    'so' => $so + 1,
                ]);
                $sectionId = (int) $pdo->lastInsertId();

                foreach (($section['fields'] ?? []) as $fso => $field) {
                    $type = $field['type'] ?? 'textbox';
                    if (!in_array($type, self::FIELD_TYPES, true)) {
                        $type = 'textbox';
                    }
                    $key = $field['field_key'] ?? 'field_' . ($fso + 1);
                    $fieldStmt->execute([
                        's'  => $sectionId,
                        'k'  => $key,
                        'l'  => $field['label'] ?? $key,
                        't'  => $type,
                        'm'  => (int) ($field['mandatory'] ?? $field['is_mandatory'] ?? 0),
                        'p'  => $field['placeholder'] ?? null,
                        'dv' => $field['default_value'] ?? null,
                        'h'  => $field['help_text'] ?? null,
                        'st' => (int) ($field['show_in_table'] ?? 0),
                        'am' => (int) ($field['allow_multiple'] ?? 0),
                        'so' => $fso + 1,
                        'sj' => isset($field['settings']) ? json_encode($field['settings']) : null,
                    ]);
                    $fieldId = (int) $pdo->lastInsertId();
                    $fieldIds[$key] = $fieldId;

                    foreach (($field['options'] ?? []) as $oo => $opt) {
                        // Accept both label/value and option_label/option_value key conventions.
                        $label = trim((string) ($opt['label'] ?? $opt['option_label'] ?? ''));
                        $value = trim((string) ($opt['value'] ?? $opt['option_value'] ?? ''));
                        if ($label === '' && $value === '') {
                            continue; // skip empty option rows
                        }
                        if ($label === '') {
                            $label = $value;
                        }
                        if ($value === '') {
                            $value = $label;
                        }
                        $optStmt->execute([
                            'f'  => $fieldId,
                            'l'  => $label,
                            'v'  => $value,
                            'so' => $oo + 1,
                            'd'  => (int) ($opt['is_default'] ?? 0),
                        ]);
                    }
                    foreach (($field['validations'] ?? []) as $val) {
                        $valStmt->execute([
                            'f'  => $fieldId,
                            'r'  => $val['rule'] ?? 'required',
                            'rv' => $val['rule_value'] ?? null,
                            'em' => $val['error_message'] ?? null,
                        ]);
                    }
                    foreach (($field['conditions'] ?? []) as $co => $cond) {
                        $pendingConditions[] = ['field_id' => $fieldId, 'cond' => $cond, 'sort' => $co + 1];
                    }
                }
            }

            // Insert conditions last so a condition may reference any field
            // (including ones defined later) by its field_key. Resolve the
            // target by field_key against the freshly inserted field ids first
            // — a re-saved structure round-trips target_field_id values that
            // refer to the PREVIOUS version's (deleted) fields, so the stable
            // field_key is the only reliable reference.
            foreach ($pendingConditions as $pc) {
                $cond = $pc['cond'];
                $targetKey = (string) ($cond['target_field_key'] ?? '');
                if ($targetKey !== '' && isset($fieldIds[$targetKey])) {
                    $targetId = $fieldIds[$targetKey];
                } elseif (isset($cond['target_field_id']) && $cond['target_field_id'] !== null) {
                    $targetId = (int) $cond['target_field_id'];
                } else {
                    $targetId = null;
                }
                if ($targetId === null) {
                    continue;
                }
                $condStmt->execute([
                    'f'  => $pc['field_id'],
                    't'  => $targetId,
                    'op' => $cond['operator'] ?? 'equals',
                    'cv' => $cond['condition_value'] ?? null,
                    'a'  => $cond['action'] ?? 'show',
                    'so' => $pc['sort'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** List forms with version info. */
    public function listForms(): array
    {
        $pdo = Connection::instance();
        $rows = $pdo->query(
            'SELECT f.*, c.name AS category_name,
                    (SELECT COUNT(*) FROM survey_records r WHERE r.form_id = f.id) AS record_count
             FROM survey_forms f
             LEFT JOIN survey_categories c ON c.id = f.category_id
             ORDER BY f.created_at DESC'
        )->fetchAll();
        return $rows;
    }

    public function findForm(int $id): ?array
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare('SELECT * FROM survey_forms WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function publishedForms(): array
    {
        $pdo = Connection::instance();
        $rows = $pdo->query(
            'SELECT f.id, f.code, f.title, f.description, f.current_version, f.updated_at
             FROM survey_forms f
             WHERE f.status = "published" AND f.is_active = 1
             ORDER BY f.updated_at DESC'
        )->fetchAll();

        foreach ($rows as $i => $form) {
            $def = $this->formDefinition((int) $form['id']);
            $rows[$i]['version'] = $def['version'] ?? null;
            $rows[$i]['sections'] = $def['sections'] ?? [];
        }
        return $rows;
    }
}
