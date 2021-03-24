<?php
declare(strict_types=1);

namespace Ethereal\Daemon;

use SetBased\Helper\Cast;

/**
 * Class for lock files.
 *
 * A lock file is a normal file identified by ist path and contains a single file line containing the PID of the
 * process that has acquired the lock.
 */
class PidLockFile
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The path of the lock file.
   *
   * @var string
   */
  private string $path;

  /**
   * The timeout for acquiring the lock file.
   *
   * @var int|null
   */
  private ?int $timeout;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Object constructor.
   *
   * @param string   $path    The path of the lock file.
   * @param int|null $timeout The timeout for acquiring the lock file.
   *                          <ul>
   *                          <li> timeout===null: wait forever trying to acquire the lock.
   *                          <li> timeout > 0: try to acquire the lock for that many seconds.
   *                          <li> timeout <=0: raise AlreadyLocked immediately if the file is already locked.
   *                          </ul>
   */
  public function __construct(string $path, ?int $timeout = null)
  {
    $this->path    = $path;
    $this->timeout = $timeout;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Tries to acquire the lock.
   *
   * @return bool True if the lock has been acquired successfully. Otherwise returns false.
   */
  public function acquire(): bool
  {
    $endTime = time() + ($this->timeout ?? 0);

    while (true)
    {
      $success = $this->writePidToPidFile();
      if ($success===true) return true;

      if (time()>$endTime && $this->timeout!==null && $this->timeout>0) return false;

      // Sleep for 0.1 second.
      usleep(100000);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns path of the lock file.
   *
   * @return string
   */
  public function path(): string
  {
    return $this->path;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads the PID from the PID file.
   *
   * @return int|null The PID or null when the PID could not be read.
   */
  public function readPid(): ?int
  {
    try
    {
      $text = file_get_contents($this->path);

      // According to the FHS 2.3 section on PID files in /var/run:
      //
      //   The file must consist of the process identifier in
      //   ASCII-encoded decimal, followed by a newline character.
      //
      //   Programs that read PID files should be somewhat flexible
      //   in what they accept; i.e., they should ignore extra
      //   whitespace, leading zeroes, absence of the trailing
      //   newline, or additional lines in the PID file.

      return Cast::toManInt(trim($text));
    }
    catch (\Throwable $exception)
    {
      // Nothing to do.
    }

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Tries to release the lock.
   *
   * @return bool  True if the lock has been released successfully. Otherwise returns false.
   */
  public function release(): bool
  {
    if (!$this->isLockedByMe()) return false;

    return $this->removePidFile();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Removes the PID file.
   *
   * @return bool True on success (including when the PID does not exists). Otherwise false.
   */
  public function removePidFile(): bool
  {
    try
    {
      if (!file_exists($this->path)) return true;

      // Possible race condition. But PHP does not have ENOENT exception/error.

      return unlink($this->path);
    }
    catch (\Throwable $exception)
    {
      return false;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Test if the lock is currently held.
   *
   * @return True if the lock is currently held. Otherwise false.
   */
  private function isLocked(): bool
  {
    return file_exists($this->path);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Test if the lock is currently held by the current process.
   *
   * @return True if the lock is currently held. Otherwise false.
   */
  private function isLockedByMe(): bool
  {
    return ($this->isLocked() && ($this->readPid()===posix_getpid()));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Writes the PID to the PID file.
   *
   * @return bool True on success. Otherwise, returns false.
   */
  private function writePidToPidFile(): bool
  {
    try
    {
      $handle = fopen($this->path, 'x');
      if ($handle===false) return false;

      // According to the FHS 2.3 section on PID files in /var/run:
      //
      //   The file must consist of the process identifier in
      //   ASCII-encoded decimal, followed by a newline character. For
      //   example, if crond was process number 25, /var/run/crond.pid
      //   would contain three characters: two, five, and newline.

      $bytes = fwrite($handle, sprintf("%s\n", posix_getpid()));
      if ($bytes===false) return false;

      $success = fclose($handle);
      if ($success===false) return false;
    }
    catch (\Throwable $exception)
    {
      return false;
    }

    return true;
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
