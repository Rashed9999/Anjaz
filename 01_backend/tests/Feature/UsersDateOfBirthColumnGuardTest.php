<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * مسارُ رفع الهوية يكتب `users.date_of_birth` مباشرةً؛ غيابُ العمود = 500 للعميل.
 * (اكتُشف بالرحلة الحقيقية بين موثّق وأميال باي على قاعدةٍ مبنيّةٍ من الهجرات وحدها.)
 */
class UsersDateOfBirthColumnGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function migrations_alone_provide_the_birth_date_column_the_identity_upgrade_writes(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'date_of_birth'));
    }
}
