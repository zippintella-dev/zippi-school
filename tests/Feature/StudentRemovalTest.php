<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolChildAbsence;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PhoenixGreensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Removing a student from the dashboard.
 *
 * Two actions, deliberately distinct:
 *   - remove from transport → reversible, journey record kept (PART K13)
 *   - delete permanently    → only when nothing operational is attached
 */
class StudentRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhoenixGreensSeeder::class);
        $this->actingAs(User::where('role', 'zippi_admin')->firstOrFail(), 'web');
    }

    private function freshChild(array $attrs = []): Child
    {
        return Child::create($attrs + [
            'school_id' => School::firstOrFail()->id,
            'admission_no' => 'NEW-' . uniqid(),
            'name' => 'Typo Row',
            'grade' => '4',
            'status' => 'active',
        ]);
    }

    /* ---------------- retire ---------------- */

    public function test_removing_from_transport_retires_rather_than_deletes(): void
    {
        $child = Child::whereHas('tripRows')->firstOrFail();

        $this->delete(route('children.destroy', $child))->assertRedirect();

        $this->assertSame('inactive', $child->fresh()->status);
        // ⚠ PART K13 — the row and its journey record must survive.
        $this->assertDatabaseHas('children', ['id' => $child->id]);
        $this->assertGreaterThan(0, $child->tripRows()->count());
    }

    /** The generator must actually stop putting them on a bus. */
    public function test_a_retired_child_is_left_out_of_generated_trips(): void
    {
        $child = Child::whereHas('stopAssignments')->firstOrFail();

        $this->delete(route('children.destroy', $child));

        $date = Carbon::now('Asia/Kolkata')->addDays(30)->toDateString();
        \App\Models\SchoolCalendar::where('date', $date)->delete();

        app(\App\Services\SchoolTripGenerator::class)
            ->generate(School::firstOrFail(), $date);

        $riding = \App\Models\SchoolTrip::where('service_date', $date)
            ->get()->flatMap(fn ($t) => $t->child_ids ?? [])->all();

        $this->assertNotContains($child->id, $riding);
    }

    public function test_a_retired_child_can_be_restored(): void
    {
        $child = $this->freshChild(['status' => 'inactive']);

        $this->post(route('children.restore', $child))->assertRedirect();

        $this->assertSame('active', $child->fresh()->status);
    }

    /* ---------------- permanent delete ---------------- */

    public function test_a_child_with_no_history_can_be_deleted_permanently(): void
    {
        $child = $this->freshChild();

        $this->delete(route('children.force-destroy', $child))
            ->assertRedirect(route('children.index'));

        $this->assertDatabaseMissing('children', ['id' => $child->id]);
    }

    /** ⚠ The guard. A child with a journey record is never destroyed. */
    public function test_a_child_with_transport_history_cannot_be_deleted(): void
    {
        $child = Child::whereHas('tripRows')->firstOrFail();

        $this->delete(route('children.force-destroy', $child))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('children', ['id' => $child->id]);
        $this->assertSame('active', $child->fresh()->status);
    }

    /** An absence alone is history enough — it is a record of a decision. */
    public function test_an_absence_alone_blocks_permanent_deletion(): void
    {
        $child = $this->freshChild();

        SchoolChildAbsence::create([
            'child_id' => $child->id,
            'service_date' => Carbon::now('Asia/Kolkata')->toDateString(),
            'direction' => 'Morning',
            'marked_by' => 'parent',
        ]);

        $this->delete(route('children.force-destroy', $child))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('children', ['id' => $child->id]);
    }

    /** A guardian left with nobody goes too — their phone holds a UNIQUE slot. */
    public function test_deleting_a_child_cleans_up_an_orphaned_guardian(): void
    {
        $child = $this->freshChild();

        $lonely = Guardian::create(['name' => 'Only Parent', 'phone' => '+919000000111']);
        $child->guardians()->attach($lonely->id, ['relationship' => 'mother', 'is_primary' => true]);

        // A guardian who also has another child must NOT be removed.
        $shared = Guardian::whereHas('children')->firstOrFail();
        $child->guardians()->attach($shared->id, ['relationship' => 'father']);

        $this->delete(route('children.force-destroy', $child));

        $this->assertDatabaseMissing('guardians', ['id' => $lonely->id]);
        $this->assertDatabaseHas('guardians', ['id' => $shared->id]);
    }

    /* ---------------- the two lists ---------------- */

    /**
     * ⚠ The Students list is the working roster. A removed student mixed in is
     * a student someone assigns a stop to by mistake.
     */
    /**
     * Is this child in the RESULT ROWS of the given list?
     *
     * Filters by admission number so pagination cannot mask the answer, and
     * looks for the row's own record link rather than the name or admission
     * number — those also appear in the search box echoed back into the page,
     * which made an earlier version of this helper always return true.
     */
    private function inList(string $route, Child $c): bool
    {
        $html = $this->get(route($route, ['q' => $c->admission_no]))
            ->assertOk()->getContent();

        return str_contains($html, route('children.show', $c) . '"');
    }

    private function onRoster(Child $c): bool
    {
        return $this->inList('children.index', $c);
    }

    private function onRemovedList(Child $c): bool
    {
        return $this->inList('children.removed', $c);
    }

    public function test_a_removed_student_disappears_from_the_students_list(): void
    {
        $child = Child::where('status', 'active')->firstOrFail();

        $this->assertTrue($this->onRoster($child));

        $this->delete(route('children.destroy', $child));

        $this->assertFalse($this->onRoster($child),
            'A removed student is still on the working roster.');
    }

    public function test_a_removed_student_appears_on_the_removed_list(): void
    {
        $child = Child::where('status', 'active')->firstOrFail();

        $this->assertFalse($this->onRemovedList($child));

        $this->delete(route('children.destroy', $child));

        $this->assertTrue($this->onRemovedList($child));
    }

    /** Restoring puts them back on the roster and off the removed list. */
    public function test_restoring_moves_a_student_back(): void
    {
        $child = Child::where('status', 'active')->firstOrFail();

        $this->delete(route('children.destroy', $child));
        $this->post(route('children.restore', $child));

        $this->assertTrue($this->onRoster($child));
        $this->assertFalse($this->onRemovedList($child));
    }

    /** The two lists must never overlap. */
    public function test_the_two_lists_are_disjoint(): void
    {
        $child = Child::where('status', 'active')->firstOrFail();
        $this->delete(route('children.destroy', $child));

        $active = Child::where('status', 'active')->pluck('id');
        $removed = Child::where('status', '!=', 'active')->pluck('id');

        $this->assertEmpty($active->intersect($removed),
            'A student appeared on both the roster and the removed list.');
        $this->assertContains($child->id, $removed->all());
    }

    /* ---------------- audit + authorization ---------------- */

    /** Invariant #4 — every removal is audited. */
    public function test_both_removals_are_audit_logged(): void
    {
        $retired = Child::whereHas('tripRows')->firstOrFail();
        $this->delete(route('children.destroy', $retired));

        $this->assertDatabaseHas('school_admin_audit_log', [
            'action' => 'child_retired', 'child_id' => $retired->id,
        ]);

        $deleted = $this->freshChild();
        $id = $deleted->id;
        $this->delete(route('children.force-destroy', $deleted));

        // ⚠ child_id is a nullOnDelete FK, so it is null once the row is gone.
        // The entry must still say WHAT was deleted — that lives in the payload.
        $entry = \App\Models\SchoolAdminAuditLog::where('action', 'child_deleted')
            ->latest('id')->firstOrFail();

        $this->assertSame($id, $entry->payload['id']);
        $this->assertSame('Typo Row', $entry->payload['name']);
        $this->assertNotEmpty($entry->payload['admission_no']);
    }

    public function test_a_guest_cannot_remove_a_student(): void
    {
        auth('web')->logout();

        $child = Child::firstOrFail();

        $this->delete(route('children.destroy', $child))->assertRedirect(route('login'));
        $this->delete(route('children.force-destroy', $child))->assertRedirect(route('login'));

        $this->assertSame('active', $child->fresh()->status);
    }
}
