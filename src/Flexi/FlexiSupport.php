<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Flexi;

/**
 * Support for FLEXIcontent sites, which expose their content types through the optional
 * `plg_webservices_flexicontent` plugin (GET/POST/PATCH /v1/flexicontent/...).
 *
 * The editor keeps the chosen content type and the secondary categories of a draft in its `fields` map under
 * the reserved key {@see self::KEY}, so no database change or file format change is needed.
 */
final class FlexiSupport
{
    /** Reserved key inside a draft's `fields` map. */
    public const KEY = '_flexi';

    /**
     * FLEXIcontent field types Grafida can edit, mapped to the editor control it uses. Every other type (image, file,
     * relation, shared media, ...) is not offered for editing and keeps its value on the site: an update does not
     * touch fields it does not mention.
     */
    private const TYPES = [
        'text'           => 'text',
        'textarea'       => 'textarea',
        'date'           => 'calendar',
        'image'          => 'media',
        'file'           => 'file',
        'mediafile'      => 'file',
        'sharedmedia'    => 'text',
        'weblink'        => 'url',
        'email'          => 'text',
        'color'          => 'color',
        'select'         => 'list',
        'radio'          => 'radio',
        'radioimage'     => 'radio',
        'selectmultiple' => 'checkboxes',
        'checkbox'       => 'checkboxes',
        'checkboximage'  => 'checkboxes',
    ];

    /**
     * The `fieldparams` of a choice field: its allowed values as the option rows the editor's controls read
     * (`name` is the label shown, `value` what is stored).
     *
     * @return array<string, mixed>
     */
    private static function optionParams(mixed $options): array
    {
        if (!is_array($options) || $options === []) {
            return [];
        }

        $rows = [];

        foreach ($options as $option) {
            if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                $value  = (string) $option['value'];
                $label  = isset($option['label']) && is_scalar($option['label']) && (string) $option['label'] !== '' ? (string) $option['label'] : $value;
                $rows[] = ['name' => $label, 'value' => $value];
            }
        }

        return $rows === [] ? [] : ['options' => $rows];
    }

    /**
     * Turns the cached type list into what the SPA needs: the types, and one field definition per field name
     * (in the shape of a Joomla custom field) carrying `flexiTypeIds`, the types the field belongs to.
     *
     * @param list<array<string, mixed>> $types
     *
     * @return array{types: list<array{id: int, name: string, alias: string}>, fields: list<array<string, mixed>>}
     */
    public function describe(array $types): array
    {
        $outTypes = [];
        $fields   = [];

        foreach ($types as $type) {
            $id = $type['id'] ?? null;

            if (!is_int($id) || $id <= 0) {
                continue;
            }

            $outTypes[] = [
                'id'    => $id,
                'name'  => is_string($type['name'] ?? null) ? $type['name'] : (string) $id,
                'alias' => is_string($type['alias'] ?? null) ? $type['alias'] : '',
            ];

            $typeFields = $type['fields'] ?? [];

            foreach (is_array($typeFields) ? $typeFields : [] as $field) {
                $name = is_array($field) ? ($field['name'] ?? null) : null;

                if (!is_array($field) || !is_string($name) || $name === '') {
                    continue;
                }

                $rawType = is_string($field['field_type'] ?? null) ? strtolower($field['field_type']) : 'text';

                // FLEXIcontent's "core properties" (ID, alias, language, access, ...) are form-layout placeholders
                // that the editor already covers with its own controls: they are not content fields.
                if ($rawType === 'coreprops') {
                    continue;
                }

                if (!isset($fields[$name])) {

                    $fields[$name] = [
                        'name'         => $name,
                        'label'        => is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $name,
                        'type'         => self::TYPES[$rawType] ?? $rawType,
                        'required'     => (bool) ($field['required'] ?? false),
                        'multiple'     => (bool) ($field['multiple'] ?? false),
                        'description'  => is_string($field['description'] ?? null) ? $field['description'] : '',
                        'fieldparams'  => self::optionParams($field['options'] ?? null),
                        'flexi'        => true,
                        'flexiTypeIds' => [],
                    ];
                }

                $fields[$name]['flexiTypeIds'][] = $id;
            }
        }

        return ['types' => $outTypes, 'fields' => array_values($fields)];
    }

    /**
     * The FLEXIcontent settings carried by a draft's `fields` map, or null when it is a plain Joomla article.
     *
     * @param array<string, mixed> $fields
     *
     * @return array{typeId: int, cats: list<int>}|null
     */
    public static function fromDraftFields(array $fields): ?array
    {
        $raw = $fields[self::KEY] ?? null;

        if (!is_array($raw)) {
            return null;
        }

        $typeId = $raw['typeId'] ?? null;

        if (!is_numeric($typeId) || (int) $typeId <= 0) {
            return null;
        }

        $cats = [];

        foreach (is_array($raw['cats'] ?? null) ? $raw['cats'] : [] as $cat) {
            if (is_numeric($cat) && (int) $cat > 0) {
                $cats[] = (int) $cat;
            }
        }

        return ['typeId' => (int) $typeId, 'cats' => array_values(array_unique($cats))];
    }

    /**
     * Field values to send for the chosen type: only fields that belong to it (an empty value clears the field).
     *
     * @param array<string, mixed>       $values Draft field values keyed by name.
     * @param list<array<string, mixed>> $defs   Field definitions from {@see describe()}.
     *
     * @return array<string, string|list<string>>
     */
    public static function valuesForType(array $values, array $defs, int $typeId): array
    {
        $out = [];

        foreach ($defs as $def) {
            $name = $def['name'] ?? null;
            $ids  = $def['flexiTypeIds'] ?? [];

            // Image fields are uploaded and sent separately (see PublishService::flexiImageValues()).
            if (!is_string($name) || in_array($def['type'] ?? null, ['media', 'file'], true) || !is_array($ids) || !in_array($typeId, $ids, true) || !array_key_exists($name, $values)) {
                continue;
            }

            $value = $values[$name];

            // An empty value is sent too: it clears the field on the site (a field left out of the request is kept).
            if (is_array($value)) {
                $out[$name] = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value), static fn (string $v): bool => $v !== ''));
            } elseif (is_scalar($value)) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Draft values for the fields of an imported FLEXIcontent item: only fields of the item's type that the editor
     * can build (text, textarea) and that hold exactly one value. A multi-valued field is not imported, because
     * saving a single value would quietly drop the others; it is simply left out and keeps its value on the site
     * (an update does not touch fields it does not mention).
     *
     * @param array<string, mixed>       $itemFields Field name => list of stored values, as the site reports them.
     * @param list<array<string, mixed>> $defs       Field definitions from {@see describe()}.
     * @param array<array-key, mixed>    $files      File-manager details by file id (`name`, `ext`), for file fields.
     *
     * @return array<string, string|list<string>>
     */
    public static function importValues(array $itemFields, array $defs, int $typeId, array $files = []): array
    {
        $out = [];

        foreach ($defs as $def) {
            $name = $def['name'] ?? null;
            $ids  = $def['flexiTypeIds'] ?? [];
            $type = $def['type'] ?? null;

            if (!is_string($name) || $type === 'media' || !in_array($type, self::TYPES, true) || !is_array($ids) || !in_array($typeId, $ids, true)) {
                continue;
            }

            $values = $itemFields[$name] ?? null;
            $values = is_array($values) ? array_values($values) : (is_scalar($values) ? [$values] : []);

            // A file field holds file-manager ids; the editor lists them by name, as JSON records it also uses for new files.
            if ($type === 'file') {
                $list = [];

                foreach ($values as $id) {
                    if (is_scalar($id) && ctype_digit((string) $id)) {
                        $info   = is_array($files[(string) $id] ?? null) ? $files[(string) $id] : [];
                        $list[] = json_encode(['id' => (int) $id, 'name' => is_string($info['name'] ?? null) ? $info['name'] : (string) $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    }
                }

                if ($list !== []) {
                    $out[$name] = $list;
                }

                continue;
            }

            // A "several values" control holds the whole list.
            if ($type === 'checkboxes') {
                $list = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $values), static fn (string $v): bool => $v !== ''));

                if ($list !== []) {
                    $out[$name] = $list;
                }

                continue;
            }

            if (count($values) === 1 && is_scalar($values[0]) && (string) $values[0] !== '') {
                $value = (string) $values[0];

                // The date control holds a day; FLEXIcontent stores "Y-m-d H:i:s".
                if ($type === 'calendar') {
                    $value = substr($value, 0, 10);
                }

                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * Names of the file fields of a type: the editor holds each as a list of files uploaded on publish.
     *
     * @param list<array<string, mixed>> $defs Field definitions from {@see describe()}.
     *
     * @return list<string>
     */
    public static function fileFieldsOf(array $defs, int $typeId): array
    {
        $names = [];

        foreach ($defs as $def) {
            $ids = $def['flexiTypeIds'] ?? [];

            if (($def['type'] ?? null) === 'file' && is_string($def['name'] ?? null) && is_array($ids) && in_array($typeId, $ids, true)) {
                $names[] = $def['name'];
            }
        }

        return $names;
    }

    /**
     * Names of the image fields of a type: the editor holds each as a local picture that is uploaded on publish.
     *
     * @param list<array<string, mixed>> $defs Field definitions from {@see describe()}.
     *
     * @return list<string>
     */
    public static function imageFieldsOf(array $defs, int $typeId): array
    {
        $names = [];

        foreach ($defs as $def) {
            $ids = $def['flexiTypeIds'] ?? [];

            if (($def['type'] ?? null) === 'media' && is_string($def['name'] ?? null) && is_array($ids) && in_array($typeId, $ids, true)) {
                $names[] = $def['name'];
            }
        }

        return $names;
    }
}
