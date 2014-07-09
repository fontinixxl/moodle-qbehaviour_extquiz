<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * TODO: Bona explicació en Ingles: explicar que aquet "behaviour" es crea
 * per a que cada "attempt ExtQuiz" tingui associat, per cada "Question",
 * els seus intents i la seva penalitació.
 * 
 * NOM EXTQUIZ ve de que aquest "behaviour" únicament s'utilitzarà des del 
 * mòdul Extendedquiz
 *
 * @package    qbehaviour
 * @subpackage extquiz
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

class qbehaviour_extquiz extends question_behaviour {

const IS_ARCHETYPAL = true;

    /**
     * Special value used for {@link question_display_options::$readonly when
     * we are showing the try again button to the student during an attempt.
     * The particular number was chosen randomly. PHP will treat it the same
     * as true, but in the renderer we reconginse it display the try again
     * button enabled even though the rest of the question is disabled.
     * @var integer
     */
    const READONLY_EXCEPT_TRY_AGAIN = 23485299;

    public function is_compatible_question(question_definition $question) {
        return $question instanceof question_automatically_gradable;
    }

    public function get_right_answer_summary() {
        return $this->question->get_right_answer_summary();
    }

    /**
     * @return bool are we are currently in the try_again state.
     */
    protected function is_try_again_state() {
        $laststep = $this->qa->get_last_step();
        $remainingattempts = $this->get_remaining_attempts();
        return $this->qa->get_state()->is_active() && $laststep->has_behaviour_var('submit') &&
                $remainingattempts >= 1;
    }

    public function adjust_display_options(question_display_options $options) {
        // We only need different behaviour in try again states.
        if (!$this->is_try_again_state()) {
            parent::adjust_display_options($options);
            return;
        }
        /*
          // Let the hint adjust the options.
          $hint = $this->get_applicable_hint();
          if (!is_null($hint)) {
          $hint->adjust_display_options($options);
          }
         */
        // Now call the base class method, but protect some fields from being overwritten.
        $save = clone($options);
        parent::adjust_display_options($options);
        $options->feedback = $save->feedback;
        $options->numpartscorrect = $save->numpartscorrect;

        // In a try-again state, everything except the try again button
        // Should be read-only. This is a mild hack to achieve this.
        if (!$options->readonly) {
            $options->readonly = self::READONLY_EXCEPT_TRY_AGAIN;
        }
    }

    public function get_applicable_hint() {
        if (!$this->is_try_again_state()) {
            return null;
        }
        return $this->question->get_hint(count($this->question->hints) -
                        $this->qa->get_last_behaviour_var('_triesleft'), $this->qa);
    }

    public function get_expected_data() {
        if ($this->is_try_again_state()) {
            return array(
                'tryagain' => PARAM_BOOL,
            );
        } else if ($this->qa->get_state()->is_active()) {
            return array(
                'submit' => PARAM_BOOL,
            );
        }
        return parent::get_expected_data();
    }

    public function get_expected_qt_data() {
        $hint = $this->get_applicable_hint();
        if (!empty($hint->clearwrong)) {
            return $this->question->get_expected_data();
        }
        return parent::get_expected_qt_data();
    }

    public function get_state_string($showcorrectness) {
        $state = $this->qa->get_state();
        if (!$state->is_active() || $state == question_state::$invalid) {
            return parent::get_state_string($showcorrectness);
        }

        if ($this->is_try_again_state()) {
            return get_string('notcomplete', 'qbehaviour_interactive');
        } else {
            $remainingattempts = $this->get_remaining_attempts();
            //debugging('get_state_string: remainingattempts = ' . $remainingattempts);
            return get_string('triesremaining', 'qbehaviour_interactive', $remainingattempts);
        }
    }

    public function init_first_step(question_attempt_step $step, $variant) {
        parent::init_first_step($step, $variant);
        //$step->set_behaviour_var('_triesleft', count($this->question->hints) + 1);
    }

    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        }
        if ($this->is_try_again_state()) {
            if ($pendingstep->has_behaviour_var('tryagain')) {
                return $this->process_try_again($pendingstep);
            } else {
                return question_attempt::DISCARD;
            }
        } else {
            if ($pendingstep->has_behaviour_var('comment')) {
                return $this->process_comment($pendingstep);
            } else if ($pendingstep->has_behaviour_var('submit')) {
                return $this->process_submit($pendingstep);
            } else {
                return $this->process_save($pendingstep);
            }
        }
    }

    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);
        } else if ($step->has_behaviour_var('finish')) {
            return $this->summarise_finish($step);
        } else if ($step->has_behaviour_var('tryagain')) {
            return get_string('tryagain', 'qbehaviour_interactive');
        } else if ($step->has_behaviour_var('submit')) {
            return $this->summarise_submit($step);
        } else {
            return $this->summarise_save($step);
        }
    }

    public function process_try_again(question_attempt_pending_step $pendingstep) {
        $pendingstep->set_state(question_state::$todo);
        return question_attempt::KEEP;
    }

    public function process_submit(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$invalid);
        } else {
            $triesleft = $this->get_remaining_attempts();
            $response = $pendingstep->get_qt_data();
            list($fraction, $state) = $this->question->grade_response($response);
            if ($state == question_state::$gradedright || $triesleft == 1) {
                $pendingstep->set_state($state);
                $pendingstep->set_fraction($this->adjust_fraction($fraction, $pendingstep));
            } else {//no esta be la resposta, xo ncara queden triesleft
                $this->update_question_remaining_attempts($triesleft - 1);
                $pendingstep->set_state(question_state::$todo);
            }
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }
        return question_attempt::KEEP;
    }

    protected function adjust_fraction($fraction, question_attempt_pending_step $pendingstep) {
        $totaltries = $this->get_num_attempts();
        $triesleft = $this->get_remaining_attempts();

        $fraction -= ($totaltries - $triesleft) * $this->get_penalty();
        $fraction = max($fraction, 0);
        //debugging("fraction = " . $fraction);
        return $fraction;
    }

    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $response = $this->qa->get_last_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);
        } else {
            list($fraction, $state) = $this->question->grade_response($response);
            $pendingstep->set_fraction($this->adjust_fraction($fraction, $pendingstep));
            $pendingstep->set_state($state);
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }

    public function process_save(question_attempt_pending_step $pendingstep) {
        $status = parent::process_save($pendingstep);
        if ($status == question_attempt::KEEP &&
                $pendingstep->get_state() == question_state::$complete) {
            $pendingstep->set_state(question_state::$todo);
        }
        return $status;
    }
    
    /*
     * ExtendedQuiz methods
     */

    public function get_quizid() {
        global $DB;
        if (!$quizid = $DB->get_field('extendedquiz_attempts', 'quiz', array('uniqueid' => $this->qa->get_usage_id()))) {
            print_error('error getting quizid from extendedquiz_attempts');
        }

        return $quizid;
    }

    public function get_num_attempts() {
        //debugging('in get_num_attempts');
        global $DB;
        $quizid = $this->get_quizid();
        $nattempt = $DB->get_record('extendedquiz_q_instances', array('quiz' => $quizid, 'question' => $this->question->id), 'nattempts');
        //debugging($nattempt->nattempts);

        return $nattempt->nattempts;
    }

    public function get_remaining_attempts() {
        global $DB;
        if (!$remainingattempts = $DB->get_field('extendedquiz_r_attempt', 'remainingattempts', array('attemptid' => $this->qa->get_usage_id(), 'question' => $this->question->id))) {
            $remainingattempts = $this->get_num_attempts();
        }
        return $remainingattempts;
    }

    public function get_penalty() {
        global $DB;
        $quizid = $this->get_quizid();
        $penalty = $DB->get_record('extendedquiz_q_instances', array('quiz' => $quizid, 'question' => $this->question->id), 'penalty');
        //debugging(print_r($penalty));

        return $penalty->penalty;
    }

    public function update_question_remaining_attempts($nattempts) {
        global $DB;
        // Retrieve from DB and update with $nattempts
        if ($obj = $DB->get_record('extendedquiz_r_attempt', array('attemptid' => $this->qa->get_usage_id(), 'question' => $this->question->id))) {
            $obj->remainingattempts = $nattempts;
            if (!$DB->update_record('extendedquiz_r_attempt', $obj)) {
                print_error('dberror', 'qtype_programmedresp');
            }

            // Create a new record
        } else {

            $obj->attemptid = $this->qa->get_usage_id();
            $obj->question = $this->question->id;
            $obj->remainingattempts = $nattempts;
            if (!$obj->id = $DB->insert_record('extendedquiz_r_attempt', $obj)) {
                print_error('dberror', 'qtype_programmedresp');
            }
        }

        return $obj;
    }

}
