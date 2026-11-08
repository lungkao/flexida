<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Flexi\FlexiSupport;

final class FlexiSupportTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function types(): array
    {
        return [
            ['id' => 1, 'name' => 'Article', 'alias' => 'article', 'fields' => [
                ['name' => 'subtitle', 'label' => 'Subtitle', 'field_type' => 'text', 'required' => true],
                ['name' => 'photo', 'label' => 'Photo', 'field_type' => 'image', 'required' => false],
            ]],
            ['id' => 3, 'name' => 'Video', 'alias' => 'video', 'fields' => [
                ['name' => 'subtitle', 'label' => 'Subtitle', 'field_type' => 'text'],
                ['name' => 'video_url', 'label' => 'Video URL', 'field_type' => 'text'],
            ]],
        ];
    }

    public function testDescribeMergesFieldsAcrossTypes(): void
    {
        $out = (new FlexiSupport())->describe($this->types());

        self::assertSame([1, 3], array_column($out['types'], 'id'));
        self::assertSame(['subtitle', 'photo', 'video_url'], array_column($out['fields'], 'name'));

        $subtitle = $out['fields'][0];
        self::assertSame([1, 3], $subtitle['flexiTypeIds']);
        self::assertSame('text', $subtitle['type']);
        self::assertTrue($subtitle['required']);
        self::assertTrue($subtitle['flexi']);

        // An image field is edited with the local-picture control.
        self::assertSame('media', $out['fields'][1]['type']);
        self::assertSame(['photo'], FlexiSupport::imageFieldsOf($out['fields'], 1));
        self::assertSame([], FlexiSupport::imageFieldsOf($out['fields'], 3));
    }

    public function testDescribeOfNonFlexiSiteIsEmpty(): void
    {
        self::assertSame(['types' => [], 'fields' => []], (new FlexiSupport())->describe([]));
    }

    public function testFromDraftFields(): void
    {
        self::assertNull(FlexiSupport::fromDraftFields([]));
        self::assertNull(FlexiSupport::fromDraftFields(['_flexi' => ['typeId' => 0]]));
        self::assertSame(
            ['typeId' => 3, 'cats' => [9, 4]],
            FlexiSupport::fromDraftFields(['_flexi' => ['typeId' => '3', 'cats' => ['9', 4, 9, 'x', 0]]]),
        );
    }

    public function testValuesForTypeKeepsOnlyThatTypesFieldsAndSendsEmptiesToClear(): void
    {
        $defs = (new FlexiSupport())->describe($this->types())['fields'];

        $values = FlexiSupport::valuesForType(
            ['subtitle' => 'Sub', 'video_url' => 'https://x.test/v', 'photo' => 'p.jpg', 'other' => 'zzz'],
            $defs,
            1,
        );

        // video_url belongs to type 3 only; "other" is not a FLEXIcontent field at all; the image field is uploaded
        // separately, so it is not among the plain values.
        self::assertSame(['subtitle' => 'Sub'], $values);
        // An emptied field is sent empty, which clears it on the site; a field the draft does not hold is left alone.
        self::assertSame(['subtitle' => ''], FlexiSupport::valuesForType(['subtitle' => ''], $defs, 1));
        self::assertSame([], FlexiSupport::valuesForType([], $defs, 1));
    }

    public function testImportValuesTakesOnlySingleValuedEditableFieldsOfTheType(): void
    {
        $defs = (new FlexiSupport())->describe($this->types())['fields'];

        $values = FlexiSupport::importValues(
            ['subtitle' => ['Sub'], 'photo' => ['p.jpg'], 'video_url' => ['https://x.test']],
            $defs,
            1,
        );

        // photo is an image field (never imported as text); video_url is a Video-type field.
        self::assertSame(['subtitle' => 'Sub'], $values);
        self::assertSame([], FlexiSupport::importValues(['subtitle' => ['a', 'b']], $defs, 1));
        self::assertSame([], FlexiSupport::importValues(['subtitle' => ['']], $defs, 1));
    }

    public function testDateFieldsAreEditableAsACalendarAndImportedAsADay(): void
    {
        $types = [['id' => 1, 'name' => 'Article', 'alias' => 'article', 'fields' => [
            ['name' => 'publication_date', 'label' => 'Date', 'field_type' => 'date'],
            ['name' => 'photo', 'label' => 'Photo', 'field_type' => 'image'],
            ['name' => 'related', 'label' => 'Related', 'field_type' => 'relation'],
        ]]];
        $defs  = (new FlexiSupport())->describe($types)['fields'];

        self::assertSame('calendar', $defs[0]['type']);
        self::assertSame(
            ['publication_date' => '2026-11-08'],
            FlexiSupport::importValues(['publication_date' => ['2026-11-08 10:30:00'], 'photo' => ['x'], 'related' => ['5', '3']], $defs, 1),
        );
    }

    public function testSharedMediaFieldIsAVideoUrlText(): void
    {
        $types = [['id' => 3, 'name' => 'Video', 'alias' => 'video', 'fields' => [
            ['name' => 'video', 'label' => 'Video', 'field_type' => 'sharedmedia'],
        ]]];
        $defs  = (new FlexiSupport())->describe($types)['fields'];

        self::assertSame('text', $defs[0]['type']);
        self::assertSame(
            ['video' => 'https://www.youtube.com/watch?v=Ql9f3e6IStw'],
            FlexiSupport::importValues(['video' => ['https://www.youtube.com/watch?v=Ql9f3e6IStw']], $defs, 3),
        );
    }

    public function testFormLayoutPlaceholdersAreNotContentFields(): void
    {
        $types = [['id' => 1, 'name' => 'Article', 'alias' => 'article', 'fields' => [
            ['name' => 'form_id', 'label' => 'ID', 'field_type' => 'coreprops'],
            ['name' => 'subtitle', 'label' => 'Subtitle', 'field_type' => 'text'],
        ]]];
        $defs  = (new FlexiSupport())->describe($types)['fields'];

        self::assertSame(['subtitle'], array_column($defs, 'name'));
    }

    public function testChoiceFieldsBecomeListRadioAndCheckboxControlsWithTheirOptions(): void
    {
        $types = [['id' => 1, 'name' => 'Article', 'alias' => 'article', 'fields' => [
            ['name' => 'topic', 'label' => 'Topic', 'field_type' => 'select', 'options' => [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => '']]],
            ['name' => 'regions', 'label' => 'Regions', 'field_type' => 'selectmultiple', 'multiple' => true, 'options' => [['value' => 'n', 'label' => 'North']]],
            ['name' => 'level', 'label' => 'Level', 'field_type' => 'radio', 'options' => [['value' => '1', 'label' => 'Low']]],
            ['name' => 'source', 'label' => 'Source', 'field_type' => 'weblink'],
        ]]];
        $defs  = (new FlexiSupport())->describe($types)['fields'];

        self::assertSame(['list', 'checkboxes', 'radio', 'url'], array_column($defs, 'type'));
        self::assertSame([['name' => 'Alpha', 'value' => 'a'], ['name' => 'b', 'value' => 'b']], $defs[0]['fieldparams']['options']);
        self::assertSame(
            ['topic' => 'a', 'regions' => ['n', 'e'], 'source' => 'https://x.test'],
            FlexiSupport::importValues(['regions' => ['n', 'e'], 'topic' => ['a'], 'source' => ['https://x.test']], $defs, 1),
        );
    }

    public function testFileFieldsAreListedByNameAndNeverSentAsPlainValues(): void
    {
        $types = [['id' => 1, 'name' => 'Article', 'alias' => 'article', 'fields' => [
            ['name' => 'docs', 'label' => 'Docs', 'field_type' => 'file', 'multiple' => true],
        ]]];
        $defs  = (new FlexiSupport())->describe($types)['fields'];

        self::assertSame('file', $defs[0]['type']);
        self::assertSame(['docs'], FlexiSupport::fileFieldsOf($defs, 1));
        self::assertSame([], FlexiSupport::valuesForType(['docs' => ['{"id":5}']], $defs, 1));

        $values = FlexiSupport::importValues(['docs' => ['5', '7']], $defs, 1, ['5' => ['name' => 'a.pdf', 'ext' => 'pdf']]);
        self::assertSame(['{"id":5,"name":"a.pdf"}', '{"id":7,"name":"7"}'], $values['docs']);
    }
}
