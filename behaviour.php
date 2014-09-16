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

class qbehaviour_extquiz extends question_behaviour_with_multiple_tries {

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

    public function adjust_display_options(question_display_options $options) {

        // We only need different behaviour in try again states.
        /* if (!$this->is_try_again_state()) {
          parent::adjust_display_options($options);
          return;
          } */
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

        // Then, if they have just Checked an answer, show them the applicable bits of feedback.
        if (!$this->qa->get_state()->is_finished() &&
                $this->qa->get_last_behaviour_var('_try')) {
            $options->feedback = $save->feedback;
            $options->correctness = $save->correctness;
            $options->numpartscorrect = $save->numpartscorrect;
        }
    }

    public function get_expected_data() {
        if ($this->qa->get_state()->is_active()) {
            return array(
                'submit' => PARAM_BOOL,
            );
        }
        return parent::get_expected_data();
    }

    public function get_state_string($showcorrectness) {
        $state = $this->qa->get_state();
        if (!$state->is_active() || $state == question_state::$invalid) {
            return parent::get_state_string($showcorrectness);
        }

        $remainingattempts = $this->get_remaining_attempts();
        return get_string('triesremaining', 'qbehaviour_interactive', $remainingattempts);
    }

    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('comment')) {
            return $this->process_comment($pendingstep);
        } else if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        } else if ($pendingstep->has_behaviour_var('submit')) {
            return $this->process_submit($pendingstep);
        } else {
            return $this->process_save($pendingstep);
        }
    }

    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);
        } else if ($step->has_behaviour_var('finish')) {
            return $this->summarise_finish($step);
        } else if ($step->has_behaviour_var('submit')) {
            return $this->summarise_submit($step);
        } else {
            return $this->summarise_save($step);
        }
    }

    public function process_next_without_answer(question_attempt_pending_step $pendingstep) {
        $pendingstep->set_fraction($this->adjust_fraction(0, $pendingstep));
        $pendingstep->set_state(question_state::$gradedwrong);
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
            $pendingstep->set_behaviour_var('_try', $triesleft - 1);
            $pendingstep->set_behaviour_var('_rawfraction', $fraction);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }
        return question_attempt::KEEP;
    }

    protected function adjust_fraction($fraction, question_attempt_pending_step $pendingstep) {
        $totaltries = $this->get_num_attempts();
        $triesleft = $this->get_remaining_attempts();

        $fraction -= ($totaltries - $triesleft) * $this->get_penalty();
        $fraction = max($fraction, 0);

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
            $pendingstep->set_behaviour_var('_try', $this->get_remaining_attempts() - 1);
            $pendingstep->set_behaviour_var('_rawfraction', $fraction);
            $pendingstep->set_fraction($this->adjust_fraction($fraction, $pendingstep));
            $pendingstep->set_state($state);
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }


    public function process_save(question_attempt_pending_step $pendingstep) {
        
        $prevgrade = $this->qa->get_fraction();
        //si la pregunta no estava puntuada (next)
        if(is_null($prevgrade)){
            $this->process_next_without_answer($pendingstep);
        }else{
            $pendingstep->set_fraction($prevgrade);
            $pendingstep->set_state($this->qa->get_state());
        }
        return question_attempt::KEEP;
    }

    /**
     * Got the most recently graded step. This is mainly intended for use by the
     * renderer.
     * @return question_attempt_step the most recently graded step.
     */
    public function get_graded_step() {
        $step = $this->qa->get_last_step_with_behaviour_var('_try');
        if ($step->has_behaviour_var('_try')) {
            return $step;
        } else {
            return null;
        }
    }

    /**
     * Determine whether a question have attempts yet
     *
     * @return bool whether have attempts remaining.
     */
    public function are_attempts_remaining() {
        if ($this->get_remaining_attempts() >= 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Determine whether a question state represents an "improvable" result,
     * that is, whether the user can still improve their score.
     *
     * @param question_state $state the question state.
     * @return bool whether the state is improvable
     */
    public function is_state_improvable(question_state $state) {
        return $state == question_state::$todo;
    }

    /**
     * @return qbehaviour_extquiz_mark_details the information about the current state-of-play, scoring-wise,
     * for this adaptive attempt.
     */
    public function get_extquiz_marks() {

        // Try to find the last graded step.
        $gradedstep = $this->get_graded_step();
        if (is_null($gradedstep) || $this->qa->get_max_mark() == 0) {
            // No score yet.
            return new qbehaviour_extquiz_mark_details(question_state::$todo);
        }

        // Work out the applicable state.
        if ($this->qa->get_state()->is_commented()) {
            $state = $this->qa->get_state();
        } else {
            $state = question_state::graded_state_for_fraction(
                            $gradedstep->get_behaviour_var('_rawfraction'));
        }

        // Prepare the grading details.
        $details = $this->extquiz_mark_details_from_step($gradedstep, $state, $this->qa->get_max_mark(), $this->get_penalty());
        $details->improvable = $this->is_state_improvable($this->qa->get_state());
        return $details;
    }

    /**
     * Actually populate the qbehaviour_adaptive_mark_details object.
     * @param question_attempt_step $gradedstep the step that holds the relevant mark details.
     * @param question_state $state the state corresponding to $gradedstep.
     * @param unknown_type $maxmark the maximum mark for this question_attempt.
     * @param unknown_type $penalty the penalty for this question, as a fraction.
     */
    protected function extquiz_mark_details_from_step(question_attempt_step $gradedstep, question_state $state, $maxmark, $penalty) {

        $totaltries = $this->get_num_attempts();
        $triesleft = $this->get_remaining_attempts();

        $details = new qbehaviour_extquiz_mark_details($state);
        $details->maxmark = $maxmark;
        $details->actualmark = $gradedstep->get_fraction() * $details->maxmark;
        $details->rawmark = $gradedstep->get_behaviour_var('_rawfraction') * $details->maxmark;
        $details->currentpenalty = $penalty * $details->maxmark;
        $details->totalpenalty = $details->currentpenalty * ($totaltries - $triesleft);
        $details->improvable = $this->is_state_improvable($gradedstep->get_state());

        return $details;
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

/**
 * This class encapsulates all the information about the current state-of-play
 * scoring-wise. It is used to communicate between the beahviour and the renderer.
 *
 * @copyright  2012 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_extquiz_mark_details {

    /** @var question_state the current state of the question. */
    public $state;

    /** @var float the maximum mark for this question. */
    public $maxmark;

    /** @var float the current mark for this question. */
    public $actualmark;

    /** @var float the raw mark for this question before penalties were applied. */
    public $rawmark;

    /** @var float the the amount of additional penalty this attempt attracted. */
    public $currentpenalty;

    /** @var float the total that will apply to future attempts. */
    public $totalpenalty;

    /** @var bool whether it is possible for this mark to be improved in future. */
    public $improvable;

    /**
     * Constructor.
     * @param question_state $state
     */
    public function __construct($state, $maxmark = null, $actualmark = null, $rawmark = null, $currentpenalty = null, $totalpenalty = null, $improvable = null) {
        $this->state = $state;
        $this->maxmark = $maxmark;
        $this->actualmark = $actualmark;
        $this->rawmark = $rawmark;
        $this->currentpenalty = $currentpenalty;
        $this->totalpenalty = $totalpenalty;
        $this->improvable = $improvable;
    }

    /**
     * Get the marks, formatted to a certain number of decimal places, in the
     * form required by calls like get_string('gradingdetails', 'qbehaviour_adaptive', $a).
     * @param int $markdp the number of decimal places required.
     * @return array ready to substitute into language strings.
     */
    public function get_formatted_marks($markdp) {
        return array(
            'max' => format_float($this->maxmark, $markdp),
            'cur' => format_float($this->actualmark, $markdp),
            'raw' => format_float($this->rawmark, $markdp),
            'penalty' => format_float($this->currentpenalty, $markdp),
            'totalpenalty' => format_float($this->totalpenalty, $markdp),
        );
    }

}
