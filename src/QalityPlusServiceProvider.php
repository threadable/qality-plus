<?php

declare(strict_types=1);

namespace Threadable\QalityPlus;

use Illuminate\Support\ServiceProvider;
use Threadable\QalityPlus\Console\CreateQalityTestCasesCommand;
use Threadable\QalityPlus\Console\PublishQalityResultsCommand;
use Threadable\QalityPlus\Publisher\HttpJiraClient;
use Threadable\QalityPlus\Publisher\HttpQalityClient;
use Threadable\QalityPlus\Publisher\JiraClient;
use Threadable\QalityPlus\Publisher\QalityClient;

final class QalityPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/qality.php', 'qality');

        $this->app->singleton(QalityClient::class, function (): QalityClient {
            return new HttpQalityClient(
                baseUrl: (string) config('qality.qality.base_url'),
                token: (string) config('qality.qality.token'),
                timeout: (int) config('qality.publisher.timeout', 30),
                retries: (int) config('qality.publisher.retries', 2),
                retryBackoffMs: (int) config('qality.publisher.retry_backoff_ms', 250),
            );
        });

        $this->app->singleton(JiraClient::class, function (): JiraClient {
            return new HttpJiraClient(
                baseUrl: (string) config('qality.jira.base_url'),
                email: config('qality.jira.email'),
                apiToken: config('qality.jira.api_token'),
                bearerToken: config('qality.jira.bearer_token'),
                timeout: (int) config('qality.publisher.timeout', 30),
                retries: (int) config('qality.publisher.retries', 2),
                retryBackoffMs: (int) config('qality.publisher.retry_backoff_ms', 250),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/qality.php' => config_path('qality.php'),
        ], 'qality-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateQalityTestCasesCommand::class,
                PublishQalityResultsCommand::class,
            ]);
        }
    }
}
