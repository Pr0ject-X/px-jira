<?php

declare(strict_types=1);

namespace Pr0jectX\PxJira\ProjectX\Plugin\CommandType\Commands;

use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\JqlFunction;
use JiraRestApi\Issue\JqlQuery;
use JiraRestApi\Issue\Transition;
use JiraRestApi\User\UserService;
use Pr0jectX\Px\Datastore\DatastoreFilesystemInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginCommandTaskBase;
use Pr0jectX\PxJira\Jira;
use Pr0jectX\PxJira\ProjectX\Plugin\CommandType\JiraCommandType;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Console\Question\Question;

class JiraCommand extends PluginCommandTaskBase
{
    /**
     * Login to the Jira service using an personal API token.
     *
     * @param array $opts
     * @option $reauthenticate
     *   Set if you need to reauthenticate with a different account.
     */
    public function jiraLogin($opts = ['reauthenticate' => false]): void
    {
        try {
            if (!$this->hasJiraAuthDatastoreData() || $opts['reauthenticate']) {
                $username = $this->doAsk(
                    (new Question($this->formatQuestion('Input Jira username')))
                        ->setValidator(function ($value) {
                            if (empty($value)) {
                                throw new \RuntimeException(
                                    'The Jira username is required!'
                                );
                            }

                            return $value;
                        })
                );
                $password = $this->doAsk(
                    (new Question($this->formatQuestion('Input Jira API token')))
                        ->setHidden(true)
                        ->setValidator(function ($value) {
                            if (empty($value)) {
                                throw new \RuntimeException(
                                    'The Jira API token is required!'
                                );
                            }

                            return $value;
                        })
                );
                $status = $this->getJiraAuthDatastore()
                    ->write([
                        'username' => $username,
                        'password' => $password
                    ]);

                if (!$status) {
                    throw new \RuntimeException(
                        'Unable to save the Jira authentication file'
                    );
                }
            }
            $userService = new UserService($this->getJiraApiConfigs());

            if ($username = $userService->getMyself()->displayName) {
                $this->success(
                    sprintf('%s is currently logged in!', $username)
                );
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * List the users assigned Jira issues.
     *
     * @param string|null $status
     *   Set the issue status to list.
     *
     * @aliases pm:issues
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function jiraIssues(string $status = null): void
    {
        Jira::printDisplayBanner();

        try {
            $rows = [];
            $headers = ['Issue #', 'Type', 'Status', 'Summary'];

            /** @var \JiraRestApi\Issue\Issue $issue */
            foreach ($this->loadProjectUserIssues() as $issue) {
                $issueStatus = $issue->fields->status->name ?? null;

                if (isset($status) && $issueStatus !== $status) {
                    continue;
                }

                $rows[] = [
                    'issue' => $issue->key ?? null,
                    'type' => $issue->fields->getIssueType()->name ?? null,
                    'status' => $issue->fields->status->name ?? null,
                    'summary' => $issue->fields->summary ?? null,
                ];
            }
            $this->io()->table($headers, $rows);
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Open an assigned Jira issue in the browser.
     *
     * @param string|null $issueNumber
     *   The jira issue number.
     *
     * @aliases jira:open
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function jiraOpenIssue($issueNumber = null)
    {
        try {
            $issueNumber = $this->chooseProjectUserIssue(
                $issueNumber
            );
            $this->taskOpenBrowser(
                "{$this->getJiraHost()}/browse/{$issueNumber}"
            )->run();
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Move an assigned Jira issues transition state.
     *
     * @param string|null $issueNumber
     *   The jira issue number.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function jiraMoveIssue(string $issueNumber = null)
    {
        Jira::printDisplayBanner();

        try {
            $issueNumber = $this->chooseProjectUserIssue($issueNumber);

            if ($assignedUser = $this->assignIssueToUser($issueNumber)) {
                $this->success(sprintf(
                    'Issue %s has successfully been assigned to "%s"!',
                    $issueNumber,
                    $assignedUser
                ));
            }

            if ($transitionState = $this->moveIssueTransitionState($issueNumber)) {
                $this->success(sprintf(
                    'Issue %s has successfully been transitioned to "%s"!',
                    $issueNumber,
                    $transitionState
                ));
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Start working an assigned Jira issue.
     *
     * @param string|null $issueNumber
     *   The jira issue number.
     *
     * @param array $opts
     * @option $base-branch
     *   Set the base branch for the new git branch.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function jiraStartIssue(string $issueNumber = null, $opts = [
        'base-branch' => 'master'
    ]): void
    {
        Jira::printDisplayBanner();

        try {
            $issueNumber = $this->chooseProjectUserIssue($issueNumber);

            if ($issueTransition = $this->moveIssueTransitionState($issueNumber)) {
                $this->success(sprintf(
                    'Issue %s has successfully been transitioned to "%s"!',
                    $issueNumber,
                    $issueTransition
                ));
            }

            if ($this->confirm(sprintf('Create a %s feature branch?', $issueNumber), true)) {
                $gitResult = $this->taskGitStack()
                    ->exec("checkout -b {$issueNumber} {$opts['base-branch']}")
                    ->run();

                if ($gitResult->wasSuccessful()) {
                    $this->success(sprintf(
                        'The %s branch has successfully been created off of %s!',
                        $issueNumber,
                        $opts['base-branch']
                    ));
                }
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Finish working an assigned Jira issue.
     *
     * @param string|null $issueNumber
     *   The jira issue number.
     *
     * @param array $opts
     * @option $main-branch
     *   The main branch to merge into.
     * @option $main-origin
     *   The main branch to merge into.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function jiraFinishIssue(string $issueNumber = null, $opts = [
        'main-branch' => 'master',
        'main-origin' => 'origin',
    ]): void
    {
        Jira::printDisplayBanner();

        try {
            $issueNumber = $this->chooseProjectUserIssue($issueNumber);

            if ($assignedUser = $this->assignIssueToUser($issueNumber)) {
                $this->success(sprintf(
                    'Issue %s has successfully been assigned to "%s"!',
                    $issueNumber,
                    $assignedUser
                ));
            }

            if ($issueTransition = $this->moveIssueTransitionState($issueNumber)) {
                $this->success(sprintf(
                    'Issue %s has successfully been transitioned to "%s"!',
                    $issueNumber,
                    $issueTransition
                ));
            }

            if (
                $this->confirm(sprintf(
                    'Merge the %s branch into the %s branch?',
                    $issueNumber,
                    $opts['main-branch']
                ))
            ) {
                $gitResult = $this->taskGitStack()
                    ->checkout($opts['main-branch'])
                    ->pull($opts['main-origin'], $opts['main-branch'])
                    ->merge($issueNumber)
                    ->run();

                if ($gitResult->wasSuccessful()) {
                    $this->success(sprintf(
                        'The %s was successfully merged into the %s branch!',
                        $issueNumber,
                        $opts['main-branch']
                    ));
                }
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Move the issue transition state.
     *
     * @param string|null $issueNumber
     *   The jira issue number.
     *
     * @return string
     *   The issues new transition state.
     *
     * @throws \JiraRestApi\JiraException
     */
    protected function moveIssueTransitionState(string $issueNumber): string
    {
        $issueService = $this->getJiraIssueService();

        $transitionsOptions = [];

        /** @var \JiraRestApi\Issue\Transition $transition */
        foreach ($issueService->getTransition($issueNumber) as $transition) {
            if (!isset($transition->name)) {
                continue;
            }
            $transitionsOptions[] = $transition->name;
        }

        $issueTransition = $this->askChoice(
            'Move the issue transition state',
            $transitionsOptions
        );
        $transition = new Transition();
        $transition->setTransitionName($issueTransition);

        $issueService->transition($issueNumber, $transition);

        return $issueTransition;
    }

    /**
     * Choose the project user issue.
     *
     * @param string|null $issueNumber
     *   The jira issue number.
     *
     * @return string
     *   The chosen jira issue number.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function chooseProjectUserIssue(string $issueNumber = null): string
    {
        $options = $this->getProjectUserIssueOptions();

        $issueNumber = $issueNumber ?? $this->askChoice(
            'Select the project issue number',
            $options
        );

        if (!in_array($issueNumber, $options)) {
            throw new \RuntimeException(
                sprintf('%s is an invalid issue number!', $issueNumber)
            );
        }

        return $issueNumber;
    }

    /**
     * Assign the issue to another user.
     *
     * @param string|null $issueNumber
     *   The jira issue number.
     * @return string|bool
     *   The issues newly assigned user; otherwise false.
     *
     * @throws \JiraRestApi\JiraException
     * @throws \JsonMapper_Exception
     */
    protected function assignIssueToUser(string $issueNumber)
    {
        if ($this->confirm('Assign the issue to someone else?', false)) {
            $userInfo = [];
            $userService = new UserService($this->getJiraApiConfigs());

            /** @var \JiraRestApi\User\User $user */
            foreach ($userService->findAssignableUsers(['project' => $this->getJiraProjectKey()]) as $user) {
                if (!isset($user->displayName) || !isset($user->accountId)) {
                    continue;
                }
                $userInfo['options'][] = $user->displayName;
                $userInfo['accountIds'][$user->displayName] = $user->accountId;
            }

            $teamMember = $this->askChoice(
                'Select the team member to assign the issue to',
                $userInfo['options']
            );

            if (!isset($userInfo['accountIds'][$teamMember])) {
                throw new \Exception(
                    'Unable to locate the users account ID'
                );
            }

            $this->getJiraIssueService()->changeAssigneeByAccountId(
                $issueNumber,
                $userInfo['accountIds'][$teamMember]
            );

            return $teamMember;
        }

        return false;
    }

    /**
     * Get an options listing of the user issues.
     *
     * @return array
     *   An array of the users issue keys.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function getProjectUserIssueOptions(): array
    {
        $options = [];

        /** @var \JiraRestApi\Issue\Issue $issue */
        foreach ($this->loadProjectUserIssues() as $issue) {
            $options[] = $issue->key;
        }

        return $options;
    }

    /**
     * Load the project issues related to the user.
     *
     * @return array
     *   An array of issues related to the user.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function loadProjectUserIssues(): array
    {
        return $this->pluginCache()->get(
            'project.user.issues',
            function (CacheItemInterface $item) {
                $item->expiresAfter(60);

                $jql = (new JqlQuery())
                    ->setProject($this->getJiraProjectKey())
                    ->setCustomField(JqlQuery::FIELD_RESOLUTION, 'Unresolved')
                    ->addInExpression(JqlQuery::FIELD_ASSIGNEE, [JqlFunction::currentUser()]);

                $jql->addAnyExpression(
                    implode(' ', [JqlQuery::KEYWORD_ORDER_BY, JqlQuery::FIELD_PRIORITY, 'DESC'])
                );

                return $this->getJiraIssueService()
                    ->search($jql->getQuery())
                    ->getIssues();
            }
        ) ?? [];
    }

    /**
     * Get the Jira issue service.
     *
     * @return \JiraRestApi\Issue\IssueService
     *    The jira issue service instance.
     *
     * @throws \JiraRestApi\JiraException
     */
    protected function getJiraIssueService(): IssueService
    {
        return new IssueService($this->getJiraApiConfigs());
    }

    /**
     * Get the Jira connected host domain.
     *
     * @return string
     *   The jira cloud host domain.
     */
    protected function getJiraHost(): string
    {
        return $this->getPlugin()->getJiraHost();
    }

    /**
     * Get the Jira project key.
     *
     * @return string
     *   The Jira project key.
     */
    protected function getJiraProjectKey(): string
    {
        return $this->getPlugin()->getJiraProjectKey();
    }

    /**
     * Get the Jira command type plugin.
     *
     * @return \Pr0jectX\PxJira\ProjectX\Plugin\CommandType\JiraCommandType
     */
    protected function getPlugin(): JiraCommandType
    {
        return $this->plugin;
    }

    /**
     * Get the Jira API configuration instance.
     *
     * @return \JiraRestApi\Configuration\ArrayConfiguration
     *   The Jira array configuration object.
     */
    protected function getJiraApiConfigs(): ArrayConfiguration
    {
        return $this->getPlugin()->jiraApiConfigs();
    }

    /**
     * Get the Jira authentication datastore.
     *
     * @return \Pr0jectX\Px\Datastore\DatastoreFilesystemInterface
     */
    protected function getJiraAuthDatastore(): DatastoreFilesystemInterface
    {
        return $this->getPlugin()->getJiraAuthDatastore();
    }

    /**
     * Determine if the Jira datastore has data.
     *
     * @return bool
     *   Return true if so; otherwise false.
     */
    protected function hasJiraAuthDatastoreData(): bool
    {
        return $this->getPlugin()->hasJiraAuthDatastoreData();
    }
}
