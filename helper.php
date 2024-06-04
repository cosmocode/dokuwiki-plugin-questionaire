<?php

use dokuwiki\ErrorHandler;
use dokuwiki\Extension\Plugin;
use dokuwiki\plugin\sqlite\SQLiteDB;

/**
 * DokuWiki Plugin questionaire (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Andreas Gohr <dokuwiki@cosmocode.de>
 */
class helper_plugin_questionaire extends Plugin
{
    protected $db;

    /**
     * Get SQLiteDB instance
     *
     * @return SQLiteDB|null
     */
    public function getDB()
    {
        if ($this->db === null) {
            try {
                $this->db = new SQLiteDB('questionaire', DOKU_PLUGIN . 'questionaire/db/');
                $this->db->getPdo()->exec('PRAGMA foreign_keys = ON');
            } catch (\Exception $exception) {
                if (defined('DOKU_UNITTEST')) throw new \RuntimeException('Could not load SQLite', 0, $exception);
                ErrorHandler::logException($exception);
                msg($this->getLang('nosqlite'), -1);
                return null;
            }
        }
        return $this->db;
    }

    /**
     * Get the questionaire meta data
     *
     * @param string $page The page to get the questionaire for
     * @return array|null Null if the questionaire has not been enabled yet
     */
    public function getQuestionaire($page)
    {
        $db = $this->getDB();
        if (!$db) return null;

        $sql = 'SELECT * FROM questionaires WHERE page = ?';
        return $db->queryRecord($sql, $page);
    }

    /**
     * Activate the questionaire for a page
     *
     * @param string $page The page to enable the questionaire for
     * @param string $user The user enabling the questionaire
     * @throws Exception if the questionaire is already enabled
     */
    public function activateQuestionaire($page, $user)
    {
        $db = $this->getDB();

        $record = [
            'page' => $page,
            'activated_on' => time(),
            'activated_by' => $user,
            'deactivated_on' => 0,
            'deactivated_by' => '',
        ];

        $db->saveRecord('questionaires', $record);
    }

    /**
     * Deactivate the questionaire for a page
     *
     * @param string $page The page to disable the questionaire for
     * @param string $user The user disabling the questionaire
     * @throws Exception if the questionaire is not enabled
     */
    public function deactivateQuestionaire($page, $user)
    {
        $db = $this->getDB();

        $record = $db->queryRecord('SELECT * FROM questionaires WHERE page = ?', $page);
        if (!$record) throw new \Exception($this->getLang('inactive'));

        $record['deactivated_on'] = time();
        $record['deactivated_by'] = $user;
        $db->saveRecord('questionaires', $record);
    }

    /**
     * Has the given user answered the questionaire for the given page?
     *
     * @param string $page The page of the questionaire
     * @param string $user The user to check
     * @return bool
     */
    public function hasUserAnswered($page, $user)
    {
        $db = $this->getDB();
        if (!$db) return false;

        $sql = 'SELECT COUNT(*) FROM answers WHERE page = ? AND answered_by = ?';
        return $db->queryValue($sql, $page, $user) > 0;
    }

    /**
     * How many users have answered this questionaire yet?
     *
     * @param string $page The page of the questionaire
     * @return int
     */
    public function numberOfResponses($page)
    {
        $db = $this->getDB();
        if (!$db) return 0;

        $sql = 'SELECT COUNT(DISTINCT answered_by) FROM answers WHERE page = ?';
        return (int)$db->queryValue($sql, $page);
    }

    /**
     * Does the given questionaire accept answers currently?
     *
     * @param string $page The page of the questionaire
     * @return bool
     */
    public function isActive($page)
    {
        $record = $this->getQuestionaire($page);
        if (!$record) return false;
        return empty($record['deactivated_on']);
    }

    /**
     * Get the question IDs for a page, based on the collected answers
     *
     * @param $page
     * @return array
     */
    public function getQuestionIDs($page)
    {
        $db = $this->getDB();
        if (!$db) return [];

        $sql = 'SELECT DISTINCT question FROM answers WHERE page = ?';
        return array_column($db->queryAll($sql, $page), 'question');
    }
}
