<?php

use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\Form\Form;
use dokuwiki\plugin\questionaire\miniYAML;

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
        return miniYAML::Load($yaml);
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml') {
            return false;
        }


        global $INPUT;
        global $ID;
        global $INFO;
        global $ACT;

        $renderer->nocache();

        if ($data === null) {
            msg($this->getLang('invalidyaml'), -1);
            return true;
        }

        /** @var helper_plugin_questionaire $helper */
        $helper = plugin_load('helper', 'questionaire');
        $user = $INPUT->server->str('REMOTE_USER');

        // handle the inputs
        if($user) {
            try {
                if ($INPUT->has('questionaire')) {
                    $this->validateInput($data, $INPUT->arr('questionaire'));
                    $this->saveInput($data, $INPUT->arr('questionaire'));
                    msg($this->getLang('success'), 1);
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
            } catch (Exception $e) {
                msg($e->getMessage(), -1);
            }
        }

        $quest = $helper->getQuestionaire($ID);

        $renderer->doc .= '<div class="plugin_questionaire">';
        if ($ACT === 'show' && $helper->hasUserAnswered($ID, $user)) {
            $renderer->doc .= '<p class="answered">' . $this->getLang('answered') . '</p>';
        } elseif ($ACT === 'show' && $quest && $quest['deactivated_on']) {
            $renderer->doc .= '<p class="deactivated">' . $this->getLang('deactivated') . '</p>';
        } else {
            $renderer->doc .= $this->addSubmitButton($this->createForm($data), $quest)->toHTML();
        }
        if($INFO['isadmin']) {
            $renderer->doc .= $this->adminPanel($quest)->toHTML();
        }
        $renderer->doc .= '</div>';

        return true;
    }

    /**
     * Create a DokuWiki Form for the questionaire
     *
     * @param array $data The questionaire configuration
     * @return Form
     */
    protected function createForm($data)
    {
        $form = new Form(['method' => 'post']);

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
     * @param Form $form
     * @param array $quest The questionaire data
     * @return Form
     */
    protected function addSubmitButton($form, $quest)
    {
        global $ACT;
        global $INPUT;
        if ($ACT !== 'show') return $form;
        if (!$quest) {
            $form->addHTML('<p class="nosubmit">' . $this->getLang('inactive') . '</p>');
        } elseif ($INPUT->server->str('REMOTE_USER') == '') {
            $form->addHTML('<p class="nosubmit">' . $this->getLang('notloggedin') . '</p>');
        } else {
            $form->addButton('questionaire[submit]', $this->getLang('submit'));
        }

        return $form;
    }

    /**
     * @return Form
     */
    protected function adminPanel($quest)
    {
        /** @var helper_plugin_questionaire $helper */
        $helper = plugin_load('helper', 'questionaire');

        $form = new Form(['method' => 'post']);
        $form->addFieldsetOpen($this->getLang('administration'));

        if ($quest) {
            $form->addHTML(
                '<p>' .
                sprintf($this->getLang('responses'), $helper->numberOfResponses($quest['page'])) .
                '</p>'
            );

            if ($quest['deactivated_on']) {
                $form->addButton('questionaire-admin', $this->getLang('enable'))->val('enable');
            } else {
                $form->addButton('questionaire-admin', $this->getLang('disable'))->val('disable');
            }

            $url = DOKU_BASE . 'lib/plugins/questionaire/dl.php?id=' . $quest['page'];

            $form->addHTML('<a href="' . $url . '" class="button">' . $this->getLang('download') . '</a>');
        } else {
            $form->addButton('questionaire-admin', $this->getLang('enable'))->val('enable');
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
        $validationError = $this->getLang('validationerror');

        foreach (array_keys($data) as $question) {
            if (!isset($input[$question])) {
                throw new Exception($validationError);
            }

            if (is_array($input[$question])) {
                if (array_filter(array_map('trim', $input[$question])) === []) {
                    throw new Exception($validationError);
                }
            } elseif (trim($input[$question]) === '') {
                throw new Exception($validationError);
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
        if (!$db) throw new \Exception($this->getLang('nodb'));

        if (!$helper->isActive($ID)) {
            throw new \Exception($this->getLang('inactive'));
        }

        if ($helper->hasUserAnswered($ID, $record['answered_by'])) {
            throw new \Exception($this->getLang('answered'));
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
            throw new \Exception($this->getLang('saveerror'), 0, $e);
        }
    }
}
