<?php

declare(strict_types=1);

namespace Pr0jectX\PxJira\ProjectX\Plugin\CommandType;

use JiraRestApi\Configuration\ArrayConfiguration;
use Pr0jectX\Px\ConfigTreeBuilder\ConfigTreeBuilder;
use Pr0jectX\Px\Datastore\DatastoreFilesystemInterface;
use Pr0jectX\Px\Datastore\JsonDatastore;
use Pr0jectX\Px\ProjectX\Plugin\PluginCommandRegisterInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginConfigurationBuilderInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginTasksBase;
use Pr0jectX\Px\PxApp;
use Pr0jectX\PxJira\ProjectX\Plugin\CommandType\Commands\JiraCommand;
use Required\Harvest\Client;
use Symfony\Component\Console\Question\Question;

class JiraCommandType extends PluginTasksBase implements PluginCommandRegisterInterface, PluginConfigurationBuilderInterface
{
    /**
     * @var string
     */
    const JIRA_AUTH_FILE = 'jira.auth.json';

    /**
     * @var string
     */
    const JIRA_HARVEST_AUTH_FILE = 'jira.harvest.auth.json';

    /**
     * {@inheritDoc}
     */
    public static function pluginId(): string
    {
        return 'jira';
    }

    /**
     * {@inheritDoc}
     */
    public static function pluginLabel(): string
    {
        return 'Jira';
    }

    /**
     * {@inheritDoc}
     */
    public function registeredCommands(): array
    {
        return [
            JiraCommand::class
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function pluginConfiguration(): ConfigTreeBuilder
    {
        return (new ConfigTreeBuilder())
            ->setQuestionInput($this->input())
            ->setQuestionOutput($this->output())
            ->createNode('cloud-host')
                ->setValue((new Question(
                    $this->formatQuestionDefault('Input the Jira cloud domain', $this->getJiraHost()),
                    $this->getJiraHost()
                ))->setValidator(function ($value) {
                    if (empty($value)) {
                        throw new \RuntimeException(
                            'The Jira cloud domain is required!'
                        );
                    }
                    return $value;
                }))
            ->end()
            ->createNode('project-key')
                ->setValue((new Question(
                    $this->formatQuestionDefault('Input the Jira project key', $this->getJiraProjectKey()),
                    $this->getJiraProjectKey()
                ))->setValidator(function ($value) {
                    if (empty($value)) {
                        throw new \RuntimeException(
                            'The Jira project key is required!'
                        );
                    }
                    return $value;
                }))
            ->end()
            ->createNode('harvest-project')
                ->setValue(new Question(
                    $this->formatQuestionDefault('Input the Harvest project code', $this->getJiraHarvestProject()),
                    $this->getJiraHarvestProject()
                ))
                ->setCondition(function () {
                    return $this->hasJiraHarvestAuthDatastoreData();
                })
            ->end();
    }

    /**
     * Get the Jira host domain.
     *
     * @return string
     *   The jira host domain url
     */
    public function getJiraHost(): string
    {
        return $this->getConfigurations()['cloud-host'] ?? '';
    }

    /**
     * Get the Jira project key.
     *
     * @return string
     *   The unique jira project key.
     */
    public function getJiraProjectKey(): string
    {
        return $this->getConfigurations()['project-key'] ?? '';
    }

    /**
     * Get the Harvest project key.
     *
     * @return string
     *   The Harvest project key.
     */
    public function getJiraHarvestProject(): string
    {
        return $this->getConfigurations()['harvest-project'] ?? '';
    }

    /**
     * Get the Jira authentication datastore.
     *
     * @return \Pr0jectX\Px\Datastore\DatastoreFilesystemInterface
     */
    public function getJiraAuthDatastore(): DatastoreFilesystemInterface
    {
        return new JsonDatastore(implode(DIRECTORY_SEPARATOR, [
            PxApp::globalTempDir(),
            static::JIRA_AUTH_FILE
        ]));
    }

    /**
     * Determine if the Jira authentication datastore has data.
     *
     * @return bool
     *   Return true if authentication data exist; otherwise false.
     */
    public function hasJiraAuthDatastoreData(): bool
    {
        return !empty($this->getJiraAuthDatastore()->read());
    }

    /**
     * Define the Jira API configurations.
     *
     * @return \JiraRestApi\Configuration\ArrayConfiguration
     */
    public function jiraApiConfigs(): ArrayConfiguration
    {
        $authInfo = $this->getJiraAuthDatastore()->read();

        if (
            !isset($authInfo['username'])
            || !isset($authInfo['password'])
        ) {
            throw new \RuntimeException(
                'Please authenticate with the Jira service using the jira:login command.'
            );
        }

        return new ArrayConfiguration([
            'jiraHost' => $this->getJiraHost(),
            'jiraUser' => $authInfo['username'] ?? null,
            'jiraPassword' => $authInfo['password'] ?? null,
        ]);
    }

    /**
     * Get the Jira Harvest authentication datastore.
     *
     * @return \Pr0jectX\Px\Datastore\DatastoreFilesystemInterface
     */
    public function getJiraHarvestDatastore(): DatastoreFilesystemInterface
    {
        return new JsonDatastore(implode(DIRECTORY_SEPARATOR, [
            PxApp::globalTempDir(),
            static::JIRA_HARVEST_AUTH_FILE
        ]));
    }

    /**
     * Determine if the Harvest authentication datastore has data.
     *
     * @return bool
     *   Return true if authentication data exist; otherwise false.
     */
    public function hasJiraHarvestAuthDatastoreData(): bool
    {
        return !empty($this->getJiraHarvestDatastore()->read());
    }

    /**
     * Define the Jira Harvest API client.
     */
    public function jiraHarvestApiClient(): Client
    {
        $datastore = $this->getJiraHarvestDatastore()->read();

        if (
            !isset($datastore['account-id'])
            || !isset($datastore['token'])
        ) {
            throw new \RuntimeException(
                'Please authenticate with the Harvest service using the jira:harvest-login command.'
            );
        }
        $client = new Client();

        $client->authenticate(
            $datastore['account-id'],
            $datastore['token']
        );

        return $client;
    }
}
