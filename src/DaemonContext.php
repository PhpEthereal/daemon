<?php
declare(strict_types=1);

namespace Plaisio\Daemon;

/**
 * Context for turning the current program into a daemon process.
 *
 * A `DaemonContext` instance represents the behaviour settings and process context for the program when it becomes a
 * daemon. The behaviour and environment is customised by setting options on the instance, before calling the
 * `daemonize` method.
 */
class DaemonContext
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * If and only if true the process must be detach from  current process into its own process group, and disassociate
   * from any  controlling terminal.
   *
   * @var bool
   */
  private bool $detachProcess;

  /**
   * The target GID for the daemon process.
   *
   * @var int
   */
  private int $gid;

  /**
   * If `initgroups` is true, the supplementary groups of the process are also initialised, with those corresponding
   * to the username for the target UID.
   *
   * @var bool
   */
  private bool $initGroups = false;

  /**
   * True if and only if this process is a daemon.
   *
   * @var bool
   */
  private bool $isDaemon = false;

  /**
   * The file access creation mask.
   *
   * @var int|null
   */
  private ?int $mask = null;

  /**
   * The PID lock file.
   *
   * @var PidLockFile|null
   */
  private ?PidLockFile $pidLockFile = null;

  /**
   * The effective root directory.
   *
   * @var string|null
   */
  private ?string $rootDirectory = null;

  /**
   * The signal handlers to be installed. Map from signal number ot callable.
   *
   * @var array<int,callable>
   */
  private array $signalHandlers;

  /**
   * The stream to redirect STDERR to.
   *
   * @var Resource|int|null
   */
  private $stderr = null;

  /**
   * The stream to redirect STDIN from.
   *
   * @var Resource|int|null
   */
  private $stdin = null;

  /**
   * The stream to redirect STDOUT to.
   *
   * @var Resource|int|null
   */
  private $stdout = null;

  /**
   * The target UID for the daemon process.
   *
   * @var int
   */
  private int $uid;

  /**
   * The working directory.
   *
   * @var string
   */
  private string $workingDirectory = '/';

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Object constructor.
   */
  public function __construct()
  {
    $this->detachProcess  = (!$this->isProcessStartedByInit());
    $this->uid            = posix_getuid();
    $this->gid            = posix_getgid();
    $this->signalHandlers = [SIGTSTP => null,
                             SIGTTIN => null,
                             SIGTTOU => null,
                             SIGTERM => [self::class, 'terminate']];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Terminates the current process.
   *
   * @param int $signalNumber The signal number.
   *
   * @throws \Exception
   */
  public static function terminate(int $signalNumber): void
  {
    echo sprintf("Terminating on signal %d\n", $signalNumber);

    exit(0);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Makes the current process a daemon.
   */
  public function daemonize(): void
  {
    if ($this->isDaemon) return;

    $this->changeRootDirectory();
    $this->preventCoreDump();
    $this->changeMask();
    $this->changeWorkingDirectory();
    $this->changeProcessOwner();
    $this->detachProcess();
    $this->installSignalHandlers();
    $this->redirectStream(STDIN, $this->stdin);
    $this->redirectStream(STDOUT, $this->stdout);
    $this->redirectStream(STDERR, $this->stderr);
    $this->acquireLock();

    $this->isDaemon = true;

    register_shutdown_function([$this, 'shutdown']);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns true and only if this process is a daemon.
   *
   * @return bool
   */
  public function isDaemon(): bool
  {
    return $this->isDaemon;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the target GID for the daemon process.
   *
   * @param int $gid
   */
  public function setGid(int $gid): void
  {
    $this->gid = $gid;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets `initgroups`.
   *
   * If `initgroups` is true, the supplementary groups of the process are also initialised, with those corresponding
   * to the username for the target UID.
   *
   * @param bool $initGroups
   */
  public function setInitGroups(bool $initGroups): void
  {
    $this->initGroups = $initGroups;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the file access creation mask.
   *
   * @param int|null $mask The file access creation mask. If null the file access creation mask will not be changed when
   *                       demonizing.
   */
  public function setMask(?int $mask): void
  {
    $this->mask = $mask;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the PID lock file.
   *
   * @param PidLockFile|null $pidLockFile The PID lock file.
   */
  public function setPidLockFile(PidLockFile $pidLockFile): void
  {
    $this->pidLockFile = $pidLockFile;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the effective root directory. If not null the daemon will chroot (change root directory) to this directory.
   *
   * @param string|null $rootDirectory The effective root directory.
   */
  public function setRootDirectory(?string $rootDirectory): void
  {
    $this->rootDirectory = $rootDirectory;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets a signal handler for a signal (will be installed upon daemonize).
   *
   * @param int           $signalNumber The signal number.
   * @param callable|null $handler      The signal handler.
   */
  public function setSignalHandler(int $signalNumber, ?callable $handler): void
  {
    $this->signalHandlers[$signalNumber] = $handler;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Set the stream to redirect STDERR to.
   *
   * @param int|Resource|null $stderr The stream to redirect STDERR to.
   */
  public function setStderr($stderr): void
  {
    $this->stderr = $stderr;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Set the stream to redirect STDIN from
   *
   * @param int|Resource|null $stdin The stream to redirect STDIN from.
   */
  public function setStdin($stdin): void
  {
    $this->stdin = $stdin;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Set the stream to redirect STDOUT to.
   *
   * @param int|Resource|null $stdout The stream to redirect STDOUT to.
   */
  public function setStdout($stdout): void
  {
    $this->stdout = $stdout;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the target UID for the daemon process.
   *
   * @param int $uid
   */
  public function setUid(int $uid): void
  {
    $this->uid = $uid;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the working directory. The default working directory is '/'.
   *
   * @param string $workingDirectory The working directory.
   */
  public function setWorkingDirectory(string $workingDirectory): void
  {
    $this->workingDirectory = $workingDirectory;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The shutdown function.
   */
  public function shutdown(): void
  {
    if (!$this->isDaemon) return;

    $this->releaseLock();

    $this->isDaemon = false;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * If required, acquires the lock.
   */
  private function acquireLock()
  {
    if ($this->pidLockFile===null) return;

    $success = $this->pidLockFile->acquire();
    if (!$success)
    {
      throw new DaemonException("Could not acquire lock on path '%s'", $this->pidLockFile->path());
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * If required changes the file access creation mask
   */
  private function changeMask(): void
  {
    try
    {
      if ($this->mask!==null)
      {
        umask($this->mask);
      }
    }
    catch (\Throwable $exception)
    {
      throw new DaemonException([$exception],
                                "Unable to change file access creation mask to 0%o: %s",
                                $this->mask,
                                $exception->getMessage());
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Changes the owning UID, GID, and groups of this process.
   */
  private function changeProcessOwner(): void
  {
    try
    {
      if ($this->initGroups)
      {
        $user = posix_getpwuid($this->uid);
        if (!is_array($user))
        {
          throw new DaemonException('Unable to get info about UID %s', $this->uid);
        }

        $success = posix_initgroups($user['name'], $this->gid);
        if ($success!==true)
        {
          throw new DaemonException('Unable to set groups access list of the current process, user: %s, GID: %d',
                                    $user['name'],
                                    $this->gid);
        }
      }
      else
      {
        $success = posix_setgid($this->gid);
        if ($success!==true)
        {
          throw new DaemonException('Unable to change GID to %s', $this->gid);
        }
      }

      $success = posix_setuid($this->uid);
      if ($success!==true)
      {
        throw new DaemonException('Unable to change UID to %s', $this->uid);
      }
    }
    catch (\Throwable $exception)
    {
      throw new DaemonException([$exception], 'Unable to change process owner: %s', $exception->getMessage());
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Changes the effective root directory.
   */
  private function changeRootDirectory()
  {
    try
    {
      if ($this->rootDirectory)
      {
        chroot($this->rootDirectory);
      }
    }
    catch (\Throwable $exception)
    {
      throw new DaemonException([$exception],
                                "Unable to change change root directory to %s: %s",
                                $this->rootDirectory,
                                $exception->getMessage());
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * If required changes the working directory.
   */
  private function changeWorkingDirectory(): void
  {
    try
    {
      chdir($this->workingDirectory);
    }
    catch (\Throwable $exception)
    {
      throw new DaemonException([$exception],
                                "Unable to change working directory to %s: %s",
                                $this->workingDirectory,
                                $exception->getMessage());
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Detach the process from parent process.
   */
  private function detachProcess()
  {
    $this->forkAndExitParent('First fork failed');
    posix_setsid();
    $this->forkAndExitParent('Second fork failed');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Forks the process.
   *
   * @param string $message The exception message when fork fails.
   */
  private function forkAndExitParent(string $message)
  {
    try
    {
      $pid = pcntl_fork();
      if ($pid>0)
      {
        posix_addendum_immediate_exit(0);
      }
    }
    catch (\Throwable $exception)
    {
      throw new DaemonException([$exception], "%s: %s", $message, $exception->getMessage());
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Installs the signal handlers.
   */
  private function installSignalHandlers()
  {
    foreach ($this->signalHandlers as $signal => $handler)
    {
      pcntl_signal($signal, $handler ?? SIG_IGN);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns true if the current process is started by 'init'. Otherwise, returns false.
   *
   * @return bool
   */
  private function isProcessStartedByInit(): bool
  {
    return (posix_getppid()==1);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Prevents a core dump.
   */
  private function preventCoreDump()
  {
    posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Redirects a system stream to a specified stream.
   *
   * @param resource $systemStream The system stream.
   * @param resource $targetStream The target stream.
   */
  private function redirectStream($systemStream, $targetStream)
  {
    if ($targetStream===null)
    {
      $targetStream = fopen('/dev/null', 'rw');
    }

    posix_addendum_dup2($targetStream, $systemStream);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * If required, releases the lock.
   */
  private function releaseLock()
  {
    if ($this->pidLockFile===null) return;

    $this->pidLockFile->release();
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
