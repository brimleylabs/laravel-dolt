<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Data\RowChange;
use BrimleyLabs\Dolt\Data\TableChange;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DiffTest extends TestCase
{
    public function test_with_no_arguments_it_shows_uncommitted_changes(): void
    {
        $this->assertTrue(Dolt::diff()->isEmpty());

        $this->makePost('One');
        $this->makePost('Two');

        $changes = Dolt::diff();

        $this->assertTrue($changes->isNotEmpty());
        $this->assertCount(1, $changes);

        $posts = $changes->table('posts');

        $this->assertSame(TableChange::MODIFIED, $posts->type);
        $this->assertTrue($posts->dataChanged);
        $this->assertFalse($posts->schemaChanged);
        $this->assertSame(2, $posts->rowsAdded);
        $this->assertSame(['tables' => 1, 'rows_added' => 2, 'rows_modified' => 0, 'rows_deleted' => 0], $changes->totals());
    }

    public function test_it_compares_two_commits_row_by_row(): void
    {
        $keep = $this->makePost('Keep');
        $edit = $this->makePost('Edit me', ['status' => 'draft']);
        $drop = $this->makePost('Drop me');
        $from = Dolt::commit('Three posts');

        $edit->update(['status' => 'published']);
        $drop->delete();
        $new = $this->makePost('Brand new');
        $to = Dolt::commit('Edit, drop and add');

        $changes = Dolt::diff($from, $to);
        $posts = $changes->table('posts');

        $this->assertSame([1, 1, 1], [$posts->rowsAdded, $posts->rowsModified, $posts->rowsDeleted]);

        $rows = $changes->rows('posts')->keyBy(fn (RowChange $row) => $row->key['id']);

        $this->assertCount(3, $rows);
        $this->assertFalse($rows->has($keep->id));

        $added = $rows[$new->id];
        $this->assertTrue($added->isAdded());
        $this->assertNull($added->before);
        $this->assertSame('Brand new', $added->after['title']);

        $modified = $rows[$edit->id];
        $this->assertTrue($modified->isModified());
        $this->assertSame(['draft', 'published'], $modified->changes()['status']);
        $this->assertNotContains('title', $modified->changed());

        $removed = $rows[$drop->id];
        $this->assertTrue($removed->isRemoved());
        $this->assertNull($removed->after);
        $this->assertSame('Drop me', $removed->before['title']);
    }

    public function test_it_accepts_refs_and_ranges(): void
    {
        Dolt::createBranch('feature');

        Dolt::on('feature', function () {
            $this->makePost('On feature');
            Dolt::commit('Feature work');
        });

        $this->makeUser();
        Dolt::commit('Main moves on');

        // Three dots: only what the branch did since it left main.
        $this->assertSame(['posts'], Dolt::diff('main...feature')->tables()->keys()->all());

        // Two refs: everything that differs between the two tips.
        $this->assertEqualsCanonicalizing(['posts', 'users'], Dolt::diff('main', 'feature')->tables()->keys()->all());
    }

    public function test_it_reports_schema_changes(): void
    {
        Schema::table('posts', fn (Blueprint $table) => $table->string('subtitle')->nullable());
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $changes = Dolt::diff();

        $this->assertTrue($changes->table('posts')->schemaChanged);
        $this->assertSame(TableChange::ADDED, $changes->table('tags')->type);

        $schema = $changes->schema()->keyBy('table');

        $this->assertStringContainsString('subtitle', $schema['posts']['to']);
        $this->assertStringNotContainsString('subtitle', $schema['posts']['from']);
        $this->assertNull($schema['tags']['from']);
    }

    public function test_it_produces_a_replayable_patch(): void
    {
        $this->makePost('Patched');

        $patch = Dolt::diff()->patch();

        $this->assertStringContainsString('INSERT INTO '.static::quoted('posts'), $patch);
        $this->assertStringContainsString('Patched', $patch);
    }

    public function test_it_serialises(): void
    {
        $this->makePost();

        $array = Dolt::diff()->toArray();

        $this->assertSame('HEAD', $array['from']);
        $this->assertSame('WORKING', $array['to']);
        $this->assertSame('posts', $array['tables'][0]['table']);
        $this->assertSame(1, Post::count());
    }
}
