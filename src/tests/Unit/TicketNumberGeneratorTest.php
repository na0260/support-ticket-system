<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TicketNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private TicketNumberGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = $this->app->make(TicketNumberGenerator::class);
    }

    #[Test]
    public function it_generates_sequential_numbers_for_a_year(): void
    {
        $this->assertSame('TKT-2026-000001', $this->generator->generate(2026));
        $this->assertSame('TKT-2026-000002', $this->generator->generate(2026));
        $this->assertSame('TKT-2026-000003', $this->generator->generate(2026));

        $this->assertDatabaseHas('ticket_sequences', ['year' => 2026, 'last_number' => 3]);
    }

    #[Test]
    public function it_defaults_to_the_current_year(): void
    {
        $this->travelTo('2026-11-01 12:00:00');

        $this->assertSame('TKT-2026-000001', $this->generator->generate());
    }

    #[Test]
    public function it_resets_the_sequence_for_each_new_year(): void
    {
        $this->assertSame('TKT-2026-000001', $this->generator->generate(2026));
        $this->assertSame('TKT-2026-000002', $this->generator->generate(2026));

        $this->assertSame('TKT-2027-000001', $this->generator->generate(2027));
        $this->assertSame('TKT-2027-000002', $this->generator->generate(2027));

        // Prior year continues independently.
        $this->assertSame('TKT-2026-000003', $this->generator->generate(2026));

        $this->assertDatabaseCount('ticket_sequences', 2);
    }

    #[Test]
    public function it_rolls_over_at_the_year_boundary_when_using_the_clock(): void
    {
        $this->travelTo('2026-12-31 23:59:59');
        $this->assertSame('TKT-2026-000001', $this->generator->generate());

        $this->travelTo('2027-01-01 00:00:00');
        $this->assertSame('TKT-2027-000001', $this->generator->generate());
    }

    #[Test]
    public function it_continues_from_a_persisted_counter(): void
    {
        DB::table('ticket_sequences')->insert(['year' => 2026, 'last_number' => 41, 'updated_at' => now()]);

        $this->assertSame('TKT-2026-000042', $this->generator->generate(2026));
    }

    #[Test]
    public function it_never_produces_duplicates_across_many_calls(): void
    {
        $numbers = [];

        for ($i = 0; $i < 250; $i++) {
            $numbers[] = $this->generator->generate(2026);
        }

        $this->assertCount(250, array_unique($numbers));
        $this->assertSame('TKT-2026-000250', end($numbers));
    }

    #[Test]
    public function it_can_be_called_inside_an_outer_transaction(): void
    {
        $number = DB::transaction(fn (): string => $this->generator->generate(2026));

        $this->assertSame('TKT-2026-000001', $number);
        $this->assertSame('TKT-2026-000002', $this->generator->generate(2026));
    }

    #[Test]
    #[DataProvider('invalidYears')]
    public function it_rejects_out_of_range_years(int $year): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->generator->generate($year);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidYears(): array
    {
        return [
            'zero' => [0],
            'three digits' => [999],
            'five digits' => [10000],
            'negative' => [-2026],
        ];
    }
}
