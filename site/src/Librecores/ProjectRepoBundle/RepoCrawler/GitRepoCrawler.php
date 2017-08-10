<?php

namespace Librecores\ProjectRepoBundle\RepoCrawler;

use Librecores\ProjectRepoBundle\Entity\Commit;
use Librecores\ProjectRepoBundle\Entity\GitSourceRepo;
use Librecores\ProjectRepoBundle\Entity\LanguageStat;
use Librecores\ProjectRepoBundle\Util\FileUtil;
use Symfony\Component\Process\Process;

/**
 * Crawl and extract metadata from a remote git repository
 *
 * This implementation performs a clone of the git repository
 * and uses ordinary git commands to fetch metadata
 */
class GitRepoCrawler extends RepoCrawler
{
    /**
     * Git clone timeout in seconds
     *
     * @internal
     * @var int
     */
    const TIMEOUT_GIT_CLONE = 3 * 60;

    /**
     * Git log timeout in seconds
     *
     * @internal
     * @var int
     */
    const TIMEOUT_GIT_LOG = 60;

    /**
     * Case-insensitive basenames without file extensions of files used for the
     * full-text of the license in a repository.
     *
     * @var array
     */
    const FILES_LICENSE = ['LICENSE', 'COPYING'];

    /**
     * Case-insensitive basenames without file extensions of files used for
     * the full-text of the description in a repository.
     *
     * @var array
     */
    const FILES_DESCRIPTION = ['README'];

    /**
     * File extensions we recognize as valid content for license and description
     * texts.
     *
     * Order matters! Put the highest priority file types at the top.
     * List from https://github.com/github/markup#markups
     *
     * @var array
     * @see self::FILES_LICENSE
     * @see self::FILES_DESCRIPTION
     */
    const FILE_EXTENSIONS = [
        '.markdown',
        '.mdown',
        '.mkdn',
        '.md',
        '.textile',
        '.rdoc',
        '.org',
        '.creole',
        '.mediawiki',
        '.wiki',
        '.rst',
        '.asciidoc',
        '.adoc',
        '.asc',
        '.pod',
        '.txt',
        '',
    ];

    private $repoClonePath = null;

    /**
     * Clean up the resources used by this repository
     */
    public function __destruct()
    {
        if ($this->repoClonePath !== null) {
            $this->logger->debug('Cleaning up repo clone directory '.$this->repoClonePath);
            FileUtil::recursiveRmdir($this->repoClonePath);
        }
    }

    /**
     * {@inheritDoc}
     * @see RepoCrawler::isValidRepoType()
     */
    public function isValidRepoType(): bool
    {
        return $this->repo instanceof GitSourceRepo;
    }

    public function updateSourceRepo()
    {
        $this->logger->info('Fetching commits for the repository '.$this->repo->getId().' of project '.
                            $this->repo->getProject()->getFqname());
        $commitRepository = $this->manager->getRepository(Commit::class);
        $lastCommit       = $commitRepository->getLatestCommit($this->repo);

        // determine if our latest commit exists and fetch new commits since
        // what we have on DB
        if ($lastCommit && $this->commitExists($lastCommit->getCommitId())) {
            $commitCount = $this->updateCommits($lastCommit->getCommitId());
        } else {
            // there has been a history rewrite
            // we drop everything and persist all commits to the DB
            //XXX: Find a way to find the common ancestor and do partial rewrites
            $commitRepository->removeAllCommits($this->repo);
            $this->repo->getCommits()->clear();
            $commitCount = $this->updateCommits();
        }

        if ($commitCount > 0) {
            $this->countLinesOfCode();
        }

        $this->manager->persist($this->repo);
        $this->manager->flush();
    }

    public function updateProject()
    {
        $project = $this->repo->getProject();
        if ($project === null) {
            $this->logger->debug('No project associated with source '.
                                 'repository '.$this->repo->getId());

            return false;
        }

        if ($project->getDescriptionTextAutoUpdate()) {
            $project->setDescriptionText($this->getDescriptionSafeHtml());
        }
        if ($project->getLicenseTextAutoUpdate()) {
            $project->setLicenseText($this->getLicenseTextSafeHtml());
        }

        $this->manager->persist($project);
        $this->manager->flush();

        return true;
    }

    /**
     * Get the path to the cloned repository
     *
     * If not yet available the repository will be cloned first.
     *
     * @return string
     */
    protected function getRepoClonePath()
    {
        if ($this->repoClonePath === null) {
            $this->cloneRepo();
        }

        return $this->repoClonePath;
    }

    /**
     * Checks whether the given commit ID exists on the default tree of the
     * repository
     *
     * @param string $commitId ID of the commit to search
     * @return bool commit exists in the tree ?
     */
    protected function commitExists(string $commitId): bool
    {
        // Stolen from https://stackoverflow.com/a/13526591

        $cwd = $this->getRepoClonePath();

        $this->logger->info('Checking commits in '.$cwd);

        $process = $this->processCreator->createProcess('git',
                                                        [
                                                            'merge-base',
                                                            '--is-ancestor',
                                                            $commitId,
                                                            'HEAD',
                                                        ]);
        $process->setWorkingDirectory($cwd);
        $this->executeProcess($process);
        $code = $process->getExitCode();
        var_dump($code);
        if (0 === $code) {
            $value = true;    // commit exists in default branch
        } else {
            if (1 === $code || 128 === $code) {
                $value = false;    // commit does not exist in repository or branch
            } else {
                throw new \RuntimeException("Unable to fetch commits from $cwd : ".$process->getErrorOutput());
            }
        }

        $this->logger->debug("Checked commits in $cwd");

        return $value;
    }

    /**
     * Get all commits in the repository since a specified commit ID or all if
     * not specified.
     *
     * @param string|null $sinceCommitId ID of commit after which the commits are to be
     *                              returned
     * @return int Commits updated
     */
    protected function updateCommits(?string $sinceCommitId = null) : int
    {
        $args = ['log', '--reverse', '--format=%H|%aN|%aE|%aD', '--shortstat',];
        if (null !== $sinceCommitId) {
            // we don't need escapeshellargs here
            // it is performed internally by ProcessBuilder
            $args[] = $sinceCommitId;
            $args[] = '...';
        }

        $cwd = $this->getRepoClonePath();

        $this->logger->info("Fetching commits in $cwd");

        $process = $this->processCreator->createProcess('git', $args);
        $process->setWorkingDirectory($cwd);
        $process->setTimeout(static::TIMEOUT_GIT_LOG);
        $this->mustExecuteProcess($process);
        $output = $process->getOutput();
        $this->logger->debug("Fetched commits from $cwd");
        return $this->parseCommits($output);
    }

    /**
     * Crawl a repositories' source code and count lines of code in each language
     *
     * Implementation uses Cloc: https://github.com/AlDanial/cloc
     *
     */
    protected function countLinesOfCode()
    {
        $process = $this->processCreator->createProcess('cloc',
                                                        ['--json', '--skip-uniqueness', $this->getRepoClonePath()]
        );

        $this->mustExecuteProcess($process);
        $result = $process->getOutput();

        $cloc = json_decode($result, true);

        $sourceStats = $this->repo->getSourceStats();
        $sourceStats->setAvailable(true)
                    ->setTotalFiles($cloc['header']['n_files'])
                    ->setTotalLinesOfCode($cloc['SUM']['code'])
                    ->setTotalBlankLines($cloc['SUM']['blank'])
                    ->setTotalLinesOfComments($cloc['SUM']['comment']);

        unset($cloc['header'], $cloc['SUM']);

        foreach ($cloc as $lang => $value) {
            $languageStat = new LanguageStat();

            $languageStat->setLanguage($lang)
                         ->setFileCount($value['nFiles'])
                         ->setLinesOfCode($value['code'])
                         ->setCommentLineCount($value['comment'])
                         ->setBlankLineCount($value['blank']);
            $sourceStats->addLanguageStat($languageStat);
        }

        $this->repo->setSourceStats($sourceStats);
        $this->manager->persist($this->repo);
    }

    /**
     * Get the description of the repository as safe HTML
     *
     * Usually this is the content of the README file.
     *
     * "Safe" HTML is stripped from all possibly malicious content, such as
     * script tags, etc.
     *
     * @return string|null the repository description, or null if none was found
     */
    protected function getDescriptionSafeHtml(): ?string
    {
        $descriptionFile = FileUtil::findFile($this->getRepoClonePath(),
                                              self::FILES_DESCRIPTION,
                                              self::FILE_EXTENSIONS);

        if ($descriptionFile === false) {
            $this->logger->debug('No description file found in the repository.');

            return null;
        }

        $this->logger->debug('Using file '.$descriptionFile.' as description.');

        try {
            $sanitizedHtml = $this->markupConverter->convertFile($descriptionFile);
        } catch (\Exception $e) {
            $this->logger->error("Unable to convert $descriptionFile to HTML ".
                                 "for license text.");

            return null;
        }

        return $sanitizedHtml;
    }

    /**
     * Get the license text of the repository as safe HTML
     *
     * Usually this license text is taken from the LICENSE or COPYING files.
     *
     * "Safe" HTML is stripped from all possibly malicious content, such as
     * script tags, etc.
     *
     * @return string|null the license text, or null if none was found
     */
    protected function getLicenseTextSafeHtml(): ?string
    {
        $licenseFile = FileUtil::findFile($this->getRepoClonePath(),
                                          self::FILES_LICENSE,
                                          self::FILE_EXTENSIONS);

        if ($licenseFile === false) {
            $this->logger->debug('Found no file containing the license text.');

            return null;
        }

        $this->logger->debug("Using file $licenseFile as license text.");

        try {
            $sanitizedHtml = $this->markupConverter->convertFile($licenseFile);
        } catch (\Exception $e) {
            $this->logger->error("Unable to convert $licenseFile.' to HTML ".
                                 "for license text.");

            return null;
        }

        return $sanitizedHtml;
    }

    /**
     * Clone a repository
     *
     * @throws \RuntimeException
     */
    private function cloneRepo()
    {
        $repoUrl             = $this->repo->getUrl();
        $this->repoClonePath = FileUtil::createTemporaryDirectory('lc-gitrepocrawler-');

        $this->logger->info('Cloning repository: '.$repoUrl);

        $process = $this->processCreator->createProcess('git', ['clone', $repoUrl, $this->repoClonePath]);
        $process->setTimeout(static::TIMEOUT_GIT_CLONE);
        $this->mustExecuteProcess($process);
        $this->logger->debug('Cloned repository '.$repoUrl);
    }

    /**
     * Parse commits from the output from git
     *
     * @param string $outputString raw output from git
     * @return int
     */
    private function parseCommits(string $outputString) : int
    {
        $this->logger->info('Parsing commits for repo '.$this->getRepoClonePath());

        $outputString = preg_replace('/^\h*\v+/m', '', trim($outputString));    // remove blank lines
        $output       = explode("\n", $outputString);  // explode lines into array

        $commits = []; // stores the array of commits
        $len     = count($output);
        for ($i = 0; $i < $len; $i++) {

            // Every commit has 4 parts, id, author name, email, commit timestamp
            // in the format id|name|email|timestamp
            // followed by an optional line for modifications
            $commitMatches = [];
            if (preg_match('/^([\da-f]+)\|(.+)\|(.+@.+)\|(.+)$/', $output[$i], $commitMatches)) {
                $contributor = $this->manager->getRepository('LibrecoresProjectRepoBundle:Contributor')
                                             ->getContributorForRepository($this->repo,
                                                                           $commitMatches[3], $commitMatches[2]);
                $date        = new \DateTime($commitMatches[4]);
                $date->setTimezone(new \DateTimeZone('UTC'));
                $commit = new Commit();
                $commit->setCommitId($commitMatches[1])
                       ->setSourceRepo($this->repo)
                       ->setDateCommitted($date)
                       ->setContributor($contributor);

                $modificationMatches = [];
                if ($i < $len - 1 &&
                    preg_match('/(\d+) files? changed(?:, (\d+) insertions?\(\+\))?(?:, (\d+) deletions?\(-\))?/',
                               $output[$i + 1], $modificationMatches)) {
                    $commit->setFilesModified($modificationMatches[1]);

                    if (array_key_exists(2, $modificationMatches) && strlen($modificationMatches[2])) {
                        $commit->setLinesAdded($modificationMatches[2]);
                    }
                    if (array_key_exists(3, $modificationMatches) && strlen($modificationMatches[3])) {
                        $commit->setLinesRemoved($modificationMatches[3]);
                    }
                    $i++;   // skip the next line
                }
                $this->manager->persist($commit);
                $commits[] = $commit;
            }
        }
        $count = count($commits);
        $this->logger->debug('Parsed '.$count.' commits for repo '.$this->getRepoClonePath());

        return $count;
    }

    /**
     * @param Process $process
     */
    private function executeProcess(Process $process)
    {
        $this->logger->debug('Executing '.$process->getCommandLine().' in '.$process->getWorkingDirectory());
        $process->run();
        $this->logger->debug('Process exited with status '.$process->getExitCode());
    }

    /**
     * @param Process $process
     */
    private function mustExecuteProcess(Process $process)
    {
        $this->logger->debug('Executing '.$process->getCommandLine().' in '.$process->getWorkingDirectory());
        $process->mustRun();
        $this->logger->debug('Process exited with status '.$process->getExitCode());
    }

}
