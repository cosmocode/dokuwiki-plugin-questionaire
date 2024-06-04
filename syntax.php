<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * DokuWiki Plugin questionaire (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Andreas Gohr <dokuwiki@cosmocode.de>
 */
class syntax_plugin_questionaire extends SyntaxPlugin
{
    /** @inheritDoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 155;
    }

    /** @inheritDoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<questionaire>.*?(?:</questionaire>)', $mode, 'plugin_questionaire');
    }


    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $yaml = substr($match, 14, -15);

        $data = [
            'yaml' => $yaml,
        ];

        return $data;
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml') {
            return false;
        }

        $renderer->nocache();
        // FIXME parse YAML in handler
        $ary = \dokuwiki\plugin\questionaire\miniYAML::Load($data['yaml']);
        if ($ary === null) {
            $renderer->doc .= '<p>Invalid YAML</p>';
            return true;
        }

        global $INPUT;
        global $ID;
        global $INFO;
        global $ACT;

        /** @var helper_plugin_questionaire $helper */
        $helper = plugin_load('helper', 'questionaire');
        $user = $INPUT->server->str('REMOTE_USER');

        // handle the inputs
        try {
            if ($INPUT->has('questionaire')) {
                $this->validateInput($ary, $INPUT->arr('questionaire'));
                $this->saveInput($ary, $INPUT->arr('questionaire'));
                msg('Thank you for your input', 1);
            }
            if ($INPUT->has('questionaire-admin') && $INFO['isadmin']) {
                switch ($INPUT->str('questionaire-admin')) {
                    case 'enable':
                        $helper->activateQuestionaire($ID, $user);
                        break;
                    case 'disable':
                        $helper->deactivateQuestionaire($ID, $user);
                        break;
                }
            }
        } catch (\Exception $e) {
            msg($e->getMessage(), -1);
        }


        // FIXME check if form should be shown and submittable
        $quest = $helper->getQuestionaire($ID);

        $renderer->doc .= '<div class="plugin_questionaire">';
        if ($ACT === 'show' && $helper->hasUserAnswered($ID, $user)) {
            $renderer->doc .= '<p class="answered">You already answered this questionaire</p>';
        } elseif ($ACT === 'show' && $quest && $quest['deactivated_on']) {
            $renderer->doc .= '<p class="deactivated">This questionaire is no longer active</p>';
        } else {
            $renderer->doc .= $this->addSubmitButton($this->createForm($ary), $quest)->toHTML();
        }
        $renderer->doc .= $this->adminPanel($quest)->toHTML();
        $renderer->doc .= '</div>';

        return true;
    }

    /**
     * Create a DokuWiki Form for the questionaire
     *
     * @param array $data The questionaire configuration
     * @return \dokuwiki\Form\Form
     */
    protected function createForm($data)
    {
        global $ACT;
        global $INPUT;

        $form = new \dokuwiki\Form\Form(['method' => 'post']);

        foreach ($data as $question => $q) {
            $form->addTagOpen('div')->addClass('question');
            $form->addTagOpen('p');
            $form->addHTML(hsc($q['q']));
            $form->addTagClose('p');

            switch ($q['t']) {
                case 'multi':
                    foreach ($q['a'] as $num => $answer) {
                        $form->addCheckbox('questionaire[' . $question . '][' . $num . ']', $answer)->val($answer);
                    }
                    break;
                case 'single':
                    foreach ($q['a'] as $answer) {
                        $form->addRadioButton('questionaire[' . $question . ']', $answer)->val($answer);
                    }
                    break;
                case 'text':
                default:
                    $form->addTextarea('questionaire[' . $question . ']');
                    break;
            }
            $form->addTagClose('div');
        }


        return $form;
    }

    /**
     * Decide if the submit button should be shown and add it to the form
     *
     * @param \dokuwiki\Form\Form $form
     * @param array $quest The questionaire data
     * @return \dokuwiki\Form\Form
     */
    protected function addSubmitButton($form, $quest)
    {
        global $ACT;
        global $INPUT;
        if ($ACT !== 'show') return $form;
        if (!$quest) {
            $form->addHTML('<p class="nosubmit">Questionaire is not active, yet</p>');
        } elseif ($INPUT->server->str('REMOTE_USER') == '') {
            $form->addHTML('<p class="nosubmit">You need to be logged in to submit the questionaire</p>');
        } else {
            $form->addButton('questionaire[submit]', 'Submit');
        }

        return $form;
    }

    /**
     * @return \dokuwiki\Form\Form
     * @todo show buttons depending on state
     */
    protected function adminPanel($quest)
    {
        /** @var helper_plugin_questionaire $helper */
        $helper = plugin_load('helper', 'questionaire');

        $form = new \dokuwiki\Form\Form(['method' => 'post']);
        $form->addFieldsetOpen('Administration');

        if ($quest) {
            $form->addHTML(
                '<p>' .
                sprintf('Responses: %s', $helper->numberOfResponses($quest['page'])) .
                '</p>'
            );

            if ($quest['deactivated_on']) {
                $form->addButton('questionaire-admin', 'Enable')->val('enable');
            } else {
                $form->addButton('questionaire-admin', 'Disable')->val('disable');
            }
            $form->addButton('questionaire-admin', 'Download Data')->val('csv');    // needs to be handled by action plugin
        } else {
            $form->addButton('questionaire-admin', 'Enable')->val('enable');
        }
        $form->addFieldsetClose();

        return $form;
    }


    /**
     * Validate the input data
     *
     * @param array $data The questionaire configuration
     * @param array $input The questionaire input
     * @throws Exception
     * @todo could check if received data is matching the available answers
     */
    protected function validateInput($data, $input)
    {
        $validationError = 'Please answer all questions';

        foreach (array_keys($data) as $question) {
            if (!isset($input[$question])) {
                throw new \Exception($validationError);
            }

            if (is_array($input[$question])) {
                if (count(array_filter(array_map('trim', $input[$question]))) === 0) {
                    throw new \Exception($validationError);
                }
            } else if (trim($input[$question]) === '') {
                throw new \Exception($validationError);
            }
        }
    }

    /**
     * Save the input data
     *
     * @param array $data The questionaire configuration
     * @param array $input The questionaire input
     * @throws Exception
     */
    protected function saveInput($data, $input)
    {
        global $INPUT;
        global $ID;

        $record = [
            'page' => $ID,
            'answered_on' => time(),
            'answered_by' => $INPUT->server->str('REMOTE_USER'),
        ];

        /** @var helper_plugin_questionaire $helper */
        $helper = plugin_load('helper', 'questionaire');
        $db = $helper->getDB();
        if (!$db) throw new \Exception('Database not available');

        if (!$helper->isActive($ID)) {
            throw new \Exception('Questionaire is not active');
        }

        if ($helper->hasUserAnswered($ID, $record['answered_by'])) {
            throw new \Exception('You already answered the questionaire');
        }

        try {
            $db->getPdo()->beginTransaction();
            foreach (array_keys($data) as $question) {
                $record['question'] = $question;

                if (is_array($input[$question])) {
                    $answers = array_filter(array_map('trim', $input[$question]));
                    foreach ($answers as $answer) {
                        $record['answer'] = $answer;
                        $db->saveRecord('answers', $record);
                    }
                } else {
                    $record['answer'] = trim($input[$question]);
                    $db->saveRecord('answers', $record);
                }
            }
            $db->getPdo()->commit();
        } catch (\Exception $e) {
            $db->getPdo()->rollBack();
            throw new \Exception('Error saving data', 0, $e);
        }
    }


    protected function isQuestionaireActive()
    {
        global $ID;

        /** @var helper_plugin_questionaire $helper */
        $helper = plugin_load('helper', 'questionaire');
        $db = $helper->getDB();
        if (!$db) return true;

        $record = $db->queryRecord('SELECT * FROM questionaires WHERE page = ?', [$ID]);
        if (!$record) return true;


        return true;
    }
}
