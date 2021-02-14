<?php

require_once 'common.php';
require_once 'db.class.php';

define('DAILY_LIMIT_MINUTES_PREFIX', 'daily_limit_minutes_');

// Background:
// https://stackoverflow.com/questions/54885178
// https://dba.stackexchange.com/questions/76788
// https://dev.mysql.com/doc/refman/8.0/en/charset-table.html
// needs 8.0+: define('CREATE_TABLE_SUFFIX', 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
define('CREATE_TABLE_SUFFIX', 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ');

// TODO: Consider caching repeated queries.
// TODO: Handle user timezone != server timezone.

DB::$success_handler = 'logDbQuery';
DB::$error_handler = 'logDbQueryError';
DB::$param_char = '|';

function logDbQuery($params) {
  Logger::Instance()->debug('DB query: ' . $params['query']);
}

function logDbQueryError($params) {
  Logger::Instance()->error('DB query: ' . $params['query']);
  Logger::Instance()->error('DB error: ' . $params['error']);
}

class KFC {

  /** Creates a new instance for use in production, using parameters from config.php. */
  public static function create($createMissingTables = false): KFC {
    return new KFC(
        DB_NAME, DB_USER, DB_PASS, function() { return time(); }, $createMissingTables);
  }

  /** Creates a new instance for use in tests. $timeFunction is used in place of system time(). */
  public static function createForTest(
      $dbName, $dbUser, $dbPass, $timeFunction): KFC {
    return new KFC($dbName, $dbUser, $dbPass, $timeFunction, true);
  }

  public function clearAllForTest(): void {
    DB::query('SET FOREIGN_KEY_CHECKS = 0');
    $rows = DB::query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = |s', DB::$dbName);
    foreach ($rows as $row) {
      DB::query('DELETE FROM `' . $row['table_name'] . '`');
    }
    DB::query('SET FOREIGN_KEY_CHECKS = 1');
    $this->insertDefaultRows();
  }

  /**
   * Connects to the database, or exits on error. $timeFunction is used in place of system time().
   */
  private function __construct($dbName, $dbUser, $dbPass, $timeFunction, $createMissingTables) {
    DB::$dbName = $dbName;
    DB::$user = $dbUser;
    DB::$password = $dbPass;
    $this->timeFunction = $timeFunction;
    if ($createMissingTables) {
      $this->createMissingTables();
    }
    // Configure global logger.
    // TODO: Report a proper error if we crash here because we weren't initialized
    // (e.g. new install and never visited the admin page).
    $config = $this->getGlobalConfig();
    if (array_key_exists('log_level', $config)) {
      Logger::Instance()->setLogLevelThreshold(strtolower($config['log_level']));
    }  // else: defaults to debug
  }

  // ---------- TABLE MANAGEMENT ----------
  // TODO: Consider FOREIGN KEY.

  // TODO: This also inserts a few default rows. Reflect that in the name.
  public function createMissingTables() {
    DB::query('SET default_storage_engine=INNODB');
    DB::query(
        'CREATE TABLE IF NOT EXISTS classes ('
        . 'id INT NOT NULL AUTO_INCREMENT, '
        . 'name VARCHAR(256) NOT NULL, '
        . 'PRIMARY KEY (id)) '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS activity ('
        . 'user VARCHAR(32) NOT NULL, '
        . 'ts BIGINT NOT NULL, '
        . 'class_id INT NOT NULL, '
        . 'focus BOOL NOT NULL, '
        . 'title VARCHAR(256) NOT NULL, '
        . 'PRIMARY KEY (user, ts, class_id, title), '
        . 'FOREIGN KEY (class_id) REFERENCES classes(id)) '
        . CREATE_TABLE_SUFFIX);  // ON DELETE CASCADE?
    DB::query(
        'CREATE TABLE IF NOT EXISTS classification ('
        . 'id INT NOT NULL AUTO_INCREMENT, '
        . 'class_id INT NOT NULL, '
        . 'priority INT NOT NULL, '
        . 're VARCHAR(1024) NOT NULL, '
        . 'PRIMARY KEY (id), '
        . 'FOREIGN KEY (class_id) REFERENCES classes(id)) '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS budgets ('
        . 'id INT NOT NULL AUTO_INCREMENT, '
        . 'name VARCHAR(256) NOT NULL, '
        . 'PRIMARY KEY (id)) '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS mappings ('
        // TODO: id auto increment?
        . 'user VARCHAR(32) NOT NULL, '
        . 'class_id INT NOT NULL, '
        . 'budget_id INT NOT NULL, '
        . 'PRIMARY KEY (user, class_id), '
        . 'FOREIGN KEY (class_id) REFERENCES classes(id), '
        . 'FOREIGN KEY (budget_id) REFERENCES budgets(id)) '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS budget_config ('
        . 'budget_id INT NOT NULL, '
        . 'k VARCHAR(100) NOT NULL, '
        . 'v VARCHAR(200) NOT NULL, '
        . 'PRIMARY KEY (budget_id, k), '
        . 'FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE RESTRICT) '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS user_config ('
        . 'user VARCHAR(32) NOT NULL, '
        . 'k VARCHAR(100) NOT NULL, '
        . 'v VARCHAR(200) NOT NULL, '
        . 'PRIMARY KEY (user, k)) '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS global_config ('
        . 'k VARCHAR(100) NOT NULL, '
        . 'v VARCHAR(200) NOT NULL, '
        . 'PRIMARY KEY (k)) '
        . CREATE_TABLE_SUFFIX);
    DB::query(
        'CREATE TABLE IF NOT EXISTS overrides ('
        . 'user VARCHAR(32) NOT NULL, '
        . 'date DATE NOT NULL, '
        . 'budget_id INT NOT NULL, '
        . 'minutes INT, '
        . 'unlocked BOOL, '
        . 'PRIMARY KEY (user, date, budget_id)) '
        . CREATE_TABLE_SUFFIX);
    $this->insertDefaultRows();
  }

  private function insertDefaultRows() {
    DB::insertIgnore('classes', ['id' => 1, 'name' => DEFAULT_CLASS_NAME]);
    DB::insertIgnore('classification', [
        'id' => 1,
        'class_id' => 1,
        'priority' => -2147483648,
        're' => '()']); // RE can't be ""
  }

  public function dropAllTablesExceptConfig() {
    $this->throwException("dropAllTablesExceptConfig() not implemented");
    // TODO: Update for new tables.
    Logger::Instance()->notice('tables dropped');
  }

  /** Delete all records prior to DateTime $date. */
  public function pruneTables($date) {
    $this->throwException("pruneTables() not implemented");
    // TODO: Update for new tables.
    Logger::Instance()->notice('tables pruned up to ' . $date->format(DateTimeInterface::ATOM));
  }

  // ---------- BUDGET/CLASS QUERIES ----------

  public function addBudget($budgetName) {
    DB::insert('budgets', ['name' => $budgetName]);
    return DB::insertId();
  }

  public function addClass($className) {
    DB::insert('classes', ['name' => $className]);
    return DB::insertId();
  }

  public function addClassification($classId, $priority, $regEx) {
    DB::insert('classification', [
        'class_id' => $classId,
        'priority' => $priority,
        're' => $regEx,
        ]);
    return DB::insertId();
  }

  public function addMapping($user, $classId, $budgetId) {
    DB::insert('mappings', [
        'user' => $user,
        'class_id' => $classId,
        'budget_id' => $budgetId,
        ]);
  }

  public function classify($titles) {
    /* TODO: This requires more fiddling, cf. https://dba.stackexchange.com/questions/24327/
      foreach ($titlesEsc as $i => $titleEsc) {
      if ($i == 0) {
      $q1 = 'SELECT "' . $titleEsc . '" AS title';
      } else {
      $q1 .= ' UNION ALL SELECT "' . $titleEsc . '"';
      }
      }
     */
    $classifications = [];
    foreach ($titles as $title) {
      $rows = DB::query(
          'SELECT classes.id, classes.name, budget_id'
          . ' FROM classification'
          . ' JOIN classes ON classes.id = classification.class_id'
          . ' LEFT JOIN mappings ON classes.id = mappings.class_id'
          . ' WHERE |s REGEXP re'
          . ' ORDER BY priority DESC'
          . ' LIMIT 1',
          $title);
      if (!$rows) { // This should never happen, the default class catches all.
        $this->throwException('Failed to classify "' . $title . '"');
      }

      $classification = [];
      $classification['class_id'] = intval($rows[0]['id']);
      $classification['class_name'] = $rows[0]['name'];
      $classification['budget_ids'] = [];
      $i = 0;
      while ($budgetId = $rows[$i++]['budget_id']) {
        $classification['budget_ids'][] = intval($budgetId);
      }
      $classifications[] = $classification;
    }
    return $classifications;
  }

  /**
   * Returns an array containing ID and name of the matching budget with the highest priority.
   * If no defined budget matches, returns the default budget id (zero) and name.
   */
  private function getBudget_unused($user, $windowTitle) {
    $q = 'SELECT budget.id, budget.name'
        . ' FROM budget_config'
        . ' JOIN budget_definition ON budget_config.budget_id = budget_definition.budget_id'
        . ' JOIN budget ON budget_config.budget_id = budget.id'
        . ' WHERE user = "' . $this->esc($user) . '" AND k = "enabled" AND v = "1"'
        . ' AND "' . $this->esc($windowTitle) . '" REGEXP budget_re'
        . ' ORDER BY priority DESC'
        . ' LIMIT 1';
    $result = $this->query($q);
    if ($row = $result->fetch_assoc()) {
      return array($row['id'], $row['name']);
    }
    return array("0", DEFAULT_BUDGET_NAME); // synthetic catch-all budget
  }

  // TODO: Decide how to treat disabled (non-enabled!?) budgets. Then check callers.

  /** Returns budget config. */
  public function getBudgetConfig($user, $budgetId) {
    $rows =
        DB::query('SELECT k, v FROM budget_config WHERE user = |s AND budget_id = |i ORDER BY k',
            $user, $budgetId);
    $config = [];
    foreach ($rows as $row) {
      $config[$row['k']] = $row['v'];
    }
    return $config;
  }

  /**
   * Returns configs of all budgets that are mapped for the specified user. The virtual key
   * 'name' is populated with the budget's name.
   * Returns a 2D array $configs[$budgetId][$key] = $value. The array is sorted by budget ID.
   */
  public function getAllBudgetConfigs($user) {
    $rows = DB::query(
        'SELECT id, name, k, v'
        . ' FROM budget_config'
        . ' RIGHT JOIN budgets ON budget_config.budget_id = budgets.id'
        . ' JOIN mappings ON mappings.budget_id = budgets.id'
        . ' WHERE user = |s'
        . ' ORDER BY id, k',
        $user);
    $configs = [];
    foreach ($rows as $row) {
      $budgetId = $row['id'];
      if (!array_key_exists($budgetId, $configs)) {
        $configs[$budgetId] = [];
      }
      $configs[$budgetId][$row['k']] = $row['v'];
      $configs[$budgetId]['name'] = $row['name'];
    }
    ksort($configs);
    return $configs;
  }

  // ---------- WRITE ACTIVITY QUERIES ----------

  /** Records the specified window titles. Return value is that of classify(). */
  // TODO: I assume this will have to return the budget ID as well, so we can tell the client
  // which windows to close.
  public function insertWindowTitles($user, $titles, $focusIndex) {
    $ts = $this->time();
    $classifications = $this->classify($titles);
    if (count($classifications) != count($titles)) {
      $this->throwException('internal error: ' . count($titles) . ' titles vs. '
          . count($classifications) . ' classifications');
    }

    $rows = [];
    foreach ($titles as $i => $title) {
      $rows[] = [
          'ts' => $ts,
          'user' => $user,
          'title' => $title,
          'class_id' => $classifications[$i]['class_id'],
          'focus' => $i == $focusIndex ? "true" : "false",
          ];
    }
    DB::replace('activity', $rows);
    return $classifications;
  }

  // ---------- CONFIG QUERIES ----------
  // TODO: Reject invalid values like '"'.

  /** Updates the specified user config value. */
  public function setUserConfig($user, $key, $value) {
    $q = 'REPLACE INTO user_config (user, k, v) VALUES ("'
        . $user . '", "' . $key . '", "' . $value . '")';
    $this->query($q);
  }

  /** Updates the specified global config value. */
  public function setGlobalConfig($key, $value) {
    $q = 'REPLACE INTO global_config (k, v) VALUES ("' . $key . '", "' . $value . '")';
    $this->query($q);
  }

  /** Deletes the specified user config value. */
  public function clearUserConfig($user, $key) {
    $q = 'DELETE FROM user_config WHERE user="' . $user . '" AND k="' . $key . '"';
    $this->query($q);
  }

  /** Deletes the specified global config value. */
  public function clearGlobalConfig($key) {
    $q = 'DELETE FROM global_config WHERE k="' . $key . '"';
    $this->query($q);
  }

  // TODO: Consider caching the config(s).

  /** Returns user config. */
  public function getUserConfig($user) {
    $result = $this->query('SELECT k, v FROM user_config WHERE user="'
        . $user . '" ORDER BY k');
    $config = array();
    while ($row = $result->fetch_assoc()) {
      $config[$row['k']] = $row['v'];
    }
    return $config;
  }

  /** Returns the config for all users. */
  public function getAllUsersConfig() {
    $result = $this->query('SELECT user, k, v FROM user_config ORDER BY user, k');
    return $result->fetch_all();
  }

  /** Returns the global config. */
  public function getGlobalConfig() {
    return DB::query('SELECT k, v FROM global_config ORDER BY k');
  }

  /** Returns all users, i.e. all distinct user keys for which at least one class is mapped. */
  public function getUsers() {
    $rows = DB::query('SELECT DISTINCT user FROM mappings ORDER BY user');
    $users = [];
    foreach ($rows as $row) {
      $users[] = $row['user'];
    }
    return $users;
  }

  // ---------- TIME SPENT/LEFT QUERIES ----------

  /**
   * Returns the time in seconds spent between $fromTime and $toTime, as a 2D array keyed by date
   * and budget ID. $toTime may be null to omit the upper limit.
   */
  public function queryTimeSpentByBudgetAndDate($user, $fromTime, $toTime) {
    // TODO: Optionally restrict to activity.focus=1.
    $toTimestamp = $toTime ? $toTime->getTimestamp() : 0;
    DB::query('SET @prev_ts = 0, @prev_s = 0'); // TODO: ":=" vs "="
    $rows = DB::query(
        'SELECT date, budget_id, SUM(s) AS sum_s'
        . ' FROM ('
        . '   SELECT'
        . '     @prev_s := IF(@prev_ts = ts, @prev_s, IF(@prev_ts = 0, 0, ts - @prev_ts)) AS s,'
        . '     @prev_ts := ts,'
        . '     DATE_FORMAT(FROM_UNIXTIME(ts), "%Y-%m-%d") AS date,'
        . '     budget_id'
        . '   FROM ('
        . '     SELECT ts, budget_id'
        . '     FROM activity'
        . '     LEFT JOIN ('
        . '       SELECT * FROM mappings WHERE user = |s0'
        . '     ) t1'
        . '     ON activity.class_id = t1.class_id'
        . '     WHERE activity.user = |s0'
        . '     AND ts >= |i'
        .       ($toTime ? ' AND ts < |i' : '')
        . '     ORDER BY ts, budget_id'
        . '   ) t2'
        . ' ) t3'
        . ' WHERE s <= 25' // TODO: 15 (sample interval) + 10 (latency compensation) magic
        . ' GROUP BY date, budget_id',
        $user, $fromTime->getTimestamp(), $toTimestamp); // TODO: Passing an extra param :-(
    $timeByBudgetAndDate = [];
    foreach ($rows as $row) {
      $budgetId = $row['budget_id'];
      if (!array_key_exists($budgetId, $timeByBudgetAndDate)) {
        $timeByBudgetAndDate[$budgetId] = [];
      }
      $timeByBudgetAndDate[$budgetId][$row['date']] = intval($row['sum_s']);
    }
    ksort($timeByBudgetAndDate, SORT_NUMERIC);
    return $timeByBudgetAndDate;
  }

  /**
   * Returns the time spent by window title and budget name, ordered by the amount of time, starting
   * at $fromTime and ending 1d (i.e. usually 24h) later. $date should therefore usually have a time
   * of 0:00.
   */
  public function queryTimeSpentByTitle($user, $fromTime) {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    // TODO: Remove "<init>" placeholder. NULL?
    $q = 'SET @prev_ts = 0, @prev_id = 0, @prev_title = "<init>";'
        . ' SELECT ts, SEC_TO_TIME(SUM(s)) as total, name, title '
        . ' FROM ('
        . '   SELECT'
        . '     ts,'
        . '     if (@prev_ts = 0, 0, ts - @prev_ts) as s,'
        . '     @prev_id as id,'
        . '     @prev_title as title,'
        . '     @prev_ts := ts,'
        . '     @prev_id := class_id,'
        . '     @prev_title := title'
        . '   FROM activity'
        . '   WHERE'
        . '     user = "' . $this->esc($user) . '"'
        . '     AND ts >= ' . $fromTime->getTimestamp()
        . '     AND ts < ' . $toTime->getTimestamp()
        . '   ORDER BY ts ASC'
        . ' ) t1'
        . ' JOIN classes ON t1.id = classes.id'
        . ' WHERE s <= 25' // TODO: 15 (sample interval) + 10 (latency compensation) magic
        . ' AND s > 0'
        . ' GROUP BY title, name'
        . ' ORDER BY total DESC';
    $q = 'SET
    @prev_ts = 0,
    @prev_s = 0,
    @prev_id = 0,
    @prev_title = "<init>";
SELECT
    ts,
    SEC_TO_TIME(SUM(s)) AS total,
    classes.NAME as class_name,
    title
FROM
    (
    SELECT
        ts,
        @prev_s := IF(@prev_ts = ts, @prev_s, IF(@prev_ts = 0, 0, ts - @prev_ts)) AS s,
        @prev_ts := ts,
        @prev_id AS class_id,
        @prev_title AS title,
        @prev_id := class_id,
        @prev_title := title
    FROM
        activity
    WHERE
        USER = "zzz" AND ts >= 1612911600 AND ts < 1612998000
    ORDER BY
        ts ASC
) t1
JOIN classes ON t1.class_id = classes.id
WHERE
    s <= 25 AND s > 0
GROUP BY
    title,
    NAME
ORDER BY
    total
DESC
    ';
    $this->multiQuery($q); // SET statement
    $result = $this->multiQueryGetNextResult();
    $timeByTitle = array();
    while ($row = $result->fetch_assoc()) {
      // TODO: This should use the client's local time format.
      $timeByTitle[] = array(
          date("Y-m-d H:i:s", $row['ts']),
          $row['total'],
          $row['name'],
          $row['title']);
    }
    $result->close();
    return $timeByTitle;
  }

  /**
   * Returns the minutes left today. In order of decreasing priority, this considers the unlock
   * requirement, an override limit, the limit configured for the day of the week, and the default
   * daily limit. For the last two, a possible weekly limit is additionally applied.
   */
  public function queryMinutesLeftToday($user, $classId) {
    $config = $this->getBudgetConfig($user, $budgetId);
    $now = $this->newDateTime();

    $overrides = $this->queryOverrides($user, $budgetId, $now);

    $minutesSpentByBudgetAndDate = $this->queryMinutesSpentByBudgetAndDate($user, getWeekStart($now), null);
    $minutesSpentByDate = get($minutesSpentByBudgetAndDate[$budgetId], array());

    return $this->computeMinutesLeftToday(
            $config, $now, $overrides, $minutesSpentByDate, $budgetId);
  }

  /**
   * Like queryTimeLeftToday() above, but returns results for all budgets in an array keyed by
   * budget ID.
   */
  public function queryTimeLeftTodayAllBudgets($user) {
    $configs = $this->getAllBudgetConfigs($user);
    $now = $this->newDateTime();

    $overridesByBudget = $this->queryOverridesByBudget($user, $now);

    $timeSpentByBudgetAndDate =
        $this->queryTimeSpentByBudgetAndDate($user, getWeekStart($now), null);

    // $minutesSpentByBudgetAndDate may contain a budget ID of NULL to indicate "no budget", which
    // $configs never contains.
    $budgetIds = array_keys($configs);
    if (array_key_exists(null, $timeSpentByBudgetAndDate)) {
      $budgetIds[] = null;
    }
    $timeLeftByBudget = array();
    foreach ($budgetIds as $budgetId) {
      $config = get($configs[$budgetId], array());
      $timeSpentByDate = get($timeSpentByBudgetAndDate[$budgetId], array());
      $overrides = get($overridesByBudget[$budgetId], array());
      $timeLeftByBudget[$budgetId] = $this->computeTimeLeftToday(
          $config, $now, $overrides, $timeSpentByDate, $budgetId);
    }
    ksort($timeLeftByBudget, SORT_NUMERIC);
    return $timeLeftByBudget;
  }

  // TODO: Placement
  private function computeTimeLeftToday($config, $now, $overrides, $timeSpentByDate) {
    $nowString = getDateString($now);
    $timeSpentToday = get($timeSpentByDate[$nowString], 0);

    // Explicit overrides have highest priority.
    $requireUnlock = get($config['require_unlock'], false);
    if ($overrides) {
      if ($requireUnlock && $overrides['unlocked'] != 1) {
        return 0;
      }
      if ($overrides['minutes'] != null) {
        return $overrides['minutes'] * 60 - $timeSpentToday;
      }
    } else if ($requireUnlock) {
      return 0;
    }

    $minutesLimitToday = get($config[DAILY_LIMIT_MINUTES_PREFIX . 'default'], 0);

    // Weekday-specific limit overrides default limit.
    $key = DAILY_LIMIT_MINUTES_PREFIX . strtolower($now->format('D'));
    $minutesLimitToday = get($config[$key], $minutesLimitToday);

    $timeLeftToday = $minutesLimitToday * 60 - $timeSpentToday;

    // A weekly limit can shorten the daily limit, but not extend it.
    if (isset($config['weekly_limit_minutes'])) {
      $timeLeftInWeek = $config['weekly_limit_minutes'] * 60 - array_sum($timeSpentByDate);
      $timeLeftToday = min($timeLeftToday, $timeLeftInWeek);
    }

    return $timeLeftToday;
  }

  // ---------- OVERRIDE QUERIES ----------

  /** Overrides the budget minutes limit for $date, which is a String in the format 'YYYY-MM-DD'. */
  public function setOverrideMinutes($user, $date, $budgetId, $minutes) {
    $this->query('INSERT INTO overrides SET'
        . ' user="' . $this->esc($user) . '",'
        . ' date="' . $date . '",'
        . ' budget_id=' . $budgetId . ','
        . ' minutes=' . $minutes
        . ' ON DUPLICATE KEY UPDATE minutes=' . $minutes);
  }

  /** Unlocks the specified budget for $date, which is a String in the format 'YYYY-MM-DD'. */
  public function setOverrideUnlock($user, $date, $budgetId) {
    $this->query('INSERT INTO overrides SET'
        . ' user="' . $this->esc($user) . '",'
        . ' date="' . $date . '",'
        . ' budget_id=' . $budgetId . ","
        . ' unlocked=1'
        . ' ON DUPLICATE KEY UPDATE unlocked=1');
  }

  /**
   * Clears all overrides (minutes and unlock) for the specified budget for $date, which is a
   *  String in the format 'YYYY-MM-DD'.
   */
  public function clearOverride($user, $date, $budgetId) {
    $this->query('DELETE FROM overrides'
        . ' WHERE user="' . $this->esc($user) . '"'
        . ' AND date="' . $date . '"'
        . ' AND budget_id=' . $budgetId);
  }

  /** Returns all overrides for the specified user, starting the week before the current week. */
  // TODO: Allow setting the date range.
  public function queryRecentOverrides($user) {
    $fromDate = getWeekStart($this->newDateTime());
    $fromDate->sub(new DateInterval('P1W'));
    $result = $this->query(
        'SELECT date, name,'
        . ' CASE WHEN minutes IS NOT NULL THEN minutes ELSE "default" END,'
        . ' CASE WHEN unlocked = 1 THEN "unlocked" ELSE "default" END'
        . ' FROM overrides'
        . ' JOIN budgets ON budget_id = id'
        . ' WHERE overrides.user="' . $user . '"'
        . ' AND date >= "' . $fromDate->format('Y-m-d') . '"'
        . ' ORDER BY date ASC');
    return $result->fetch_all();
  }

  /** Returns overrides in an associative array, or an empty array if none are defined. */
  private function queryOverrides($user, $budgetId, $dateTime) {
    $result = $this->query('SELECT budget_id, minutes, unlocked FROM overrides'
        . ' WHERE user="' . $user . '"'
        . ' AND date="' . getDateString($dateTime) . '"'
        . ' AND budget_id="' . $budgetId . '"');
    if ($row = $result->fetch_assoc()) {
      return array('minutes' => $row['minutes'], 'unlocked' => $row['unlocked']);
    }
    return array();
  }

  /** Returns all overrides as a 2D array keyed first by budget ID, then by override. */
  private function queryOverridesByBudget($user, $now) {
    $rows = DB::query('SELECT budget_id, minutes, unlocked FROM overrides'
        . ' WHERE user = |s'
        . ' AND date = |s',
        $user, getDateString($now));
    $overridesByBudget = array();
    // PK is (user, date, budget_id), so there is at most one row per budget_id.
    foreach ($rows as $row) {
      $overridesByBudget[$row['budget_id']] = array('minutes' => $row['minutes'], 'unlocked' => $row['unlocked']);
    }
    return $overridesByBudget;
  }

  // ---------- DEBUG/SPECIAL/DUBIOUS/OBNOXIOUS QUERIES ----------

  /**
   * Returns the sequence of window titles for the specified user and date. This will typically be
   * a long array and is intended for debugging.
   */
  public function queryTitleSequence($user, $fromTime) {
    $toTime = (clone $fromTime)->add(new DateInterval('P1D'));
    $q = 'SELECT ts, name, title FROM activity JOIN budget ON budget_id = id'
        . ' WHERE user = "' . $this->esc($user) . '"'
        . ' AND ts >= ' . $fromTime->getTimestamp()
        . ' AND ts < ' . $toTime->getTimestamp()
        . ' ORDER BY ts DESC';
    $result = $this->query($q);
    $windowTitles = array();
    while ($row = $result->fetch_assoc()) {
      // TODO: This should use the client's local time format.
      $windowTitles[] = array(
          date("Y-m-d H:i:s", $row['ts']),
          $row['name'],
          $row['title']);
    }
    return $windowTitles;
  }

  // ---------- MYSQL LOW LEVEL HELPERS ----------

  /**
   * Like query() above, but using mysqli::multi_query. Returns the first query result, throwing an
   * exception on failure. Subsequent results are available via multiQueryGetNextResult() below.
   */
  private function multiQuery($multiQuery, $logLevel = 'debug') {
    Logger::Instance()->log($logLevel, 'Multi query: ' . $multiQuery);
    $this->multiQuery = $multiQuery;
    if (!$this->mysqli->multi_query($multiQuery)) {
      $this->throwMySqlErrorException($multiQuery);
    }
    return $this->mysqli->use_result();
  }

  /**
   * Returns the second, third etc. result from calling multiQuery() above, throwing an exception on
   * failure.
   */
  private function multiQueryGetNextResult() {
    if (!$this->mysqli->next_result()) {
      $this->throwMySqlErrorException($this->multiQuery);
    }
    return $this->mysqli->use_result();
  }

  private function throwMySqlErrorException($query) {
    $message = 'MySQL error ' . $this->mysqli->errno . ': ' . $this->mysqli->error . ' -- Query: ' . $query;
    $this->throwException($message);
  }

  private function throwException($message) {
    Logger::Instance()->critical($message);
    throw new Exception($message);
  }

  /** Returns epoch time in seconds. Allows manual dependency injection in test. */
  private function time() {
    return ($this->timeFunction)();
  }

  /**
   * Helper method to return a new DateTime object representing $this->time() in the server's
   * timezone.
   */
  private function newDateTime() {
    $d = new DateTime();
    $d->setTimestamp($this->time());
    return $d;
  }

}