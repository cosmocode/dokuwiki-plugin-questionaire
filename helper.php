<?php

use dokuwiki\ErrorHandler;
use dokuwiki\Extension\Plugin;
use dokuwiki\plugin\sqlite\SQLiteDB;

/**
 * DokuWiki Plugin questionnaire (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Andreas Gohr <dokuwiki@cosmocode.de>
 */
class helper_plugin_questionnaire extends Plugin
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
                $this->db = new SQLiteDB('questionnaire', DOKU_PLUGIN . 'questionnaire/db/');
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
     * Get the questionnaire meta data
     *
     * @param string $page The page to get the questionnaire for
     * @return array|null Null if the questionnaire has not been enabled yet
     */
    public function getQuestionnaire($page)
    {
        $db = $this->getDB();
        if (!$db) return null;

        $sql = 'SELECT * FROM questionnaires WHERE page = ?';
        return $db->queryRecord($sql, $page);
    }

    /**
     * Activate the questionnaire for a page
     *
     * @param string $page The page to enable the questionnaire for
     * @param string $user The user enabling the questionnaire
     * @throws Exception if the questionnaire is already enabled
     */
    public function activateQuestionnaire($page, $user)
    {
        $db = $this->getDB();

        $record = [
            'page' => $page,
            'activated_on' => time(),
            'activated_by' => $user,
            'deactivated_on' => 0,
            'deactivated_by' => '',
        ];

        $db->saveRecord('questionnaires', $record);
    }

    /**
     * Deactivate the questionnaire for a page
     *
     * @param string $page The page to disable the questionnaire for
     * @param string $user The user disabling the questionnaire
     * @throws Exception if the questionnaire is not enabled
     */
    public function deactivateQuestionnaire($page, $user)
    {
        $db = $this->getDB();

        $record = $db->queryRecord('SELECT * FROM questionnaires WHERE page = ?', $page);
        if (!$record) throw new \Exception($this->getLang('inactive'));

        $record['deactivated_on'] = time();
        $record['deactivated_by'] = $user;
        $db->saveRecord('questionnaires', $record);
    }

    /**
     * Has the given user answered the questionnaire for the given page?
     *
     * @param string $page The page of the questionnaire
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
     * How many users have answered this questionnaire yet?
     *
     * @param string $page The page of the questionnaire
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
     * Does the given questionnaire accept answers currently?
     *
     * @param string $page The page of the questionnaire
     * @return bool
     */
    public function isActive($page)
    {
        $record = $this->getQuestionnaire($page);
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
