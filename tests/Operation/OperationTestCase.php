<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Tests\Operation;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kent013\PrismPrompt\Tests\TestCase;

abstract class OperationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('prism-prompt.jobs.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // テスト用 fake scope テーブル作成
        $this->app['db']->connection()->getSchemaBuilder()
            ->create('fake_scopes', function ($table): void {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        // v0.13.0: string 主キー (ULID 想定) の scope テーブル
        $this->app['db']->connection()->getSchemaBuilder()
            ->create('fake_ulid_scopes', function ($table): void {
                $table->ulid('id')->primary();
                $table->string('name');
                $table->timestamps();
            });
        // app 側の llm_call_logs を模した最小テーブル
        $this->app['db']->connection()->getSchemaBuilder()
            ->create('llm_call_logs', function ($table): void {
                $table->id();
                $table->string('correlation_id')->index();
                $table->timestamp('created_at')->nullable();
            });
    }

    protected function makeFakeScope(): FakeScope
    {
        return FakeScope::create(['name' => 'scope-'.bin2hex(random_bytes(4))]);
    }

    protected function makeFakeUlidScope(): FakeUlidScope
    {
        return FakeUlidScope::create(['name' => 'ulid-scope-'.bin2hex(random_bytes(4))]);
    }
}

class FakeScope extends Model
{
    protected $table = 'fake_scopes';

    protected $guarded = [];
}

class FakeUlidScope extends Model
{
    use HasUlids;

    protected $table = 'fake_ulid_scopes';

    protected $guarded = [];
}
