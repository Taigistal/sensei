<?php
/**
 * File containing the class Sensei_REST_API_Lesson_Quiz_Controller.
 *
 * @package sensei
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sensei Lesson Quiz REST API endpoints.
 *
 * @package Sensei
 * @author  Automattic
 * @since   3.9.0
 */
class Sensei_REST_API_Lesson_Quiz_Controller extends \WP_REST_Controller {

	/**
	 * Routes namespace.
	 *
	 * @var string
	 */
	protected $namespace;

	/**
	 * Routes prefix.
	 *
	 * @var string
	 */
	protected $rest_base = 'lesson-quiz';

	/**
	 * Sensei_REST_API_Quiz_Controller constructor.
	 *
	 * @param string $namespace Routes namespace.
	 */
	public function __construct( $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register the REST API endpoints for quiz.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/(?P<lesson_id>[0-9]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_quiz' ],
					'permission_callback' => [ $this, 'can_user_get_quiz' ],
					'args'                => [
						'context' => [
							'type'              => 'string',
							'default'           => 'view',
							'enum'              => [ 'view', 'edit' ],
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						],
					],
				],
				'schema' => [ $this, 'get_schema' ],
			]
		);
	}

	/**
	 * Check user permission for reading a quiz.
	 *
	 * @param WP_REST_Request $request WordPress request object.
	 *
	 * @return bool|WP_Error Whether the user can read a quiz. Error if not found.
	 */
	public function can_user_get_quiz( WP_REST_Request $request ) {
		$lesson = get_post( (int) $request->get_param( 'lesson_id' ) );
		if ( ! $lesson || 'lesson' !== $lesson->post_type ) {
			return new WP_Error(
				'sensei_lesson_quiz_missing_lesson',
				__( 'Lesson not found.', 'sensei-lms' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return wp_get_current_user()->ID === (int) $lesson->post_author || current_user_can( 'manage_options' );
	}

	/**
	 * Get the quiz.
	 *
	 * @param WP_REST_Request $request WordPress request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_quiz( WP_REST_Request $request ) : WP_REST_Response {
		$lesson = get_post( (int) $request->get_param( 'lesson_id' ) );
		$quiz   = Sensei()->lesson->lesson_quizzes( $lesson->ID );

		if ( ! $quiz ) {
			return new WP_REST_Response( null, 204 );
		}

		$response = new WP_REST_Response();
		$response->set_data( $this->get_quiz_data( get_post( $quiz ) ) );

		return $response;
	}

	/**
	 * Helper method which retrieves quiz options.
	 *
	 * @param WP_Post $quiz
	 *
	 * @return array
	 */
	private function get_quiz_data( WP_Post $quiz ) : array {
		$post_meta = get_post_meta( $quiz->ID );
		return [
			'options'   => [
				'pass_required'         => ! empty( $post_meta['_pass_required'][0] ) && 'on' === $post_meta['_pass_required'][0],
				'quiz_passmark'         => empty( $post_meta['_quiz_passmark'][0] ) ? 0 : (int) $post_meta['_quiz_passmark'][0],
				'auto_grade'            => ! empty( $post_meta['_quiz_grade_type'][0] ) && 'auto' === $post_meta['_quiz_grade_type'][0],
				'allow_retakes'         => ! empty( $post_meta['_enable_quiz_reset'][0] ) && 'on' === $post_meta['_enable_quiz_reset'][0],
				'show_questions'        => empty( $post_meta['_show_questions'][0] ) ? null : (int) $post_meta['_show_questions'][0],
				'random_question_order' => ! empty( $post_meta['_random_question_order'][0] ) && 'yes' === $post_meta['_random_question_order'][0],
			],
			'questions' => $this->get_quiz_questions( $quiz ),
		];
	}

	/**
	 * Returns all the questions of a quiz.
	 *
	 * @param WP_Post $quiz The quiz.
	 *
	 * @return array The array of the questions as defined by the schema.
	 */
	private function get_quiz_questions( WP_Post $quiz ) : array {
		$questions = Sensei()->quiz->get_questions( $quiz->ID );

		if ( empty( $questions ) ) {
			return [];
		}

		$quiz_questions     = [];
		$multiple_questions = [];
		foreach ( $questions as $question ) {
			if ( 'multiple_question' === $question->post_type ) {
				$multiple_questions[] = $question;
			} else {
				$quiz_questions[] = $this->get_question( $question );
			}
		}

		foreach ( $multiple_questions as $multiple_question ) {
			$quiz_questions = array_merge( $quiz_questions, $this->get_questions_from_category( $multiple_question, wp_list_pluck( $quiz_questions, 'id' ) ) );
		}

		return $quiz_questions;
	}

	/**
	 * This method retrieves questions as they are defined by a 'multiple_question' post type.
	 *
	 * @param WP_Post $multiple_question  The multiple question.
	 * @param array   $excluded_questions An array of question ids to exclude.
	 *
	 * @return array
	 */
	private function get_questions_from_category( WP_Post $multiple_question, array $excluded_questions ) : array {
		$category = (int) get_post_meta( $multiple_question->ID, 'category', true );
		$number   = (int) get_post_meta( $multiple_question->ID, 'number', true );

		$args = [
			'post_type'        => 'question',
			'posts_per_page'   => $number,
			'orderby'          => 'title',
			'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Query limited by the number of questions.
				[
					'taxonomy' => 'question-category',
					'field'    => 'term_id',
					'terms'    => $category,
				],
			],
			'post_status'      => 'any',
			'suppress_filters' => 0,
			'post__not_in'     => $excluded_questions,
		];

		$questions = get_posts( $args );

		return array_map( [ $this, 'get_question' ], $questions );
	}

	/**
	 * Returns a question as defined by the schema.
	 *
	 * @param WP_Post $question The question post.
	 *
	 * @return array The question array.
	 */
	private function get_question( WP_Post $question ) : array {
		$common_properties        = $this->get_question_common_properties( $question );
		$type_specific_properties = $this->get_question_type_specific_properties( $question, $common_properties['type'] );

		return array_merge( $common_properties, $type_specific_properties );
	}

	/**
	 * Generates the common question properties.
	 *
	 * @param WP_Post $question The question post.
	 *
	 * @return array The question properties.
	 */
	private function get_question_common_properties( WP_Post $question ) : array {
		$question_meta = get_post_meta( $question->ID );
		return [
			'id'          => $question->ID,
			'title'       => $question->post_title,
			'description' => $question->post_content,
			'grade'       => Sensei()->question->get_question_grade( $question->ID ),
			'type'        => Sensei()->question->get_question_type( $question->ID ),
			'shared'      => ! empty( $question_meta['_quiz_id'] ) && count( $question_meta['_quiz_id'] ) > 1,
			'categories'  => wp_get_post_terms( $question->ID, 'question-category', [ 'fields' => 'ids' ] ),
		];
	}

	/**
	 * Generates the type specific question properties.
	 *
	 * @param WP_Post $question      The question post.
	 * @param string  $question_type The question type.
	 *
	 * @return array The question properties.
	 */
	private function get_question_type_specific_properties( WP_Post $question, string $question_type ) : array {
		$type_specific_properties = [];

		switch ( $question_type ) {
			case 'multiple-choice':
				$type_specific_properties = $this->get_multiple_choice_properties( $question );
				break;
			case 'boolean':
				$type_specific_properties['answer'] = 'true' === get_post_meta( $question->ID, '_question_right_answer', true );

				$answer_feedback = get_post_meta( $question->ID, '_answer_feedback', true );
				if ( ! empty( $answer_feedback ) ) {
					$type_specific_properties['answer_feedback'] = $answer_feedback;
				}
				break;
			case 'gap-fill':
				$type_specific_properties = $this->get_gap_fill_properties( $question );
				break;
			case 'single-line':
				$answer = get_post_meta( $question->ID, '_question_right_answer', true );
				if ( ! empty( $answer ) ) {
					$type_specific_properties['teacher_notes'] = $answer;
				}
				break;
			case 'multi-line':
				$teacher_notes = get_post_meta( $question->ID, '_question_right_answer', true );
				if ( ! empty( $teacher_notes ) ) {
					$type_specific_properties['teacher_notes'] = $teacher_notes;
				}
				break;
			case 'file-upload':
				$teacher_notes = get_post_meta( $question->ID, '_question_right_answer', true );
				if ( ! empty( $teacher_notes ) ) {
					$type_specific_properties['teacher_notes'] = $teacher_notes;
				}

				$student_help = get_post_meta( $question->ID, '_question_wrong_answers', true );
				if ( ! empty( $student_help[0] ) ) {
					$type_specific_properties['student_help'] = $student_help[0];
				}
				break;
		}

		/**
		 * Allows modification of type specific question properties.
		 *
		 * @since 3.9.0
		 *
		 * @param array   $type_specific_properties The properties of the question.
		 * @param string  $question_type            The question type.
		 * @param WP_Post $question                 The question post.
		 */
		return apply_filters( 'sensei_question_type_specific_properties', $type_specific_properties, $question_type, $question );
	}

	/**
	 * Helper method which generates the properties for gap fill questions.
	 *
	 * @param WP_Post $question The question post.
	 *
	 * @return array The gap fill question properties.
	 */
	private function get_gap_fill_properties( WP_Post $question ) : array {
		$right_answer_meta = get_post_meta( $question->ID, '_question_right_answer', true );

		if ( empty( $right_answer_meta ) ) {
			return [];
		}

		$result      = [];
		$text_values = explode( '||', $right_answer_meta );

		if ( isset( $text_values[0] ) ) {
			$result['before'] = $text_values[0];
		}

		if ( isset( $text_values[1] ) ) {
			$result['gap'] = explode( '|', $text_values[1] );
		}

		if ( isset( $text_values[2] ) ) {
			$result['after'] = $text_values[2];
		}

		return $result;
	}

	/**
	 * Helper method which generates the properties for multiple choice questions.
	 *
	 * @param WP_Post $question The question post.
	 *
	 * @return array The multiple choice question properties.
	 */
	private function get_multiple_choice_properties( WP_Post $question ) : array {
		$type_specific_properties = [
			'random_order' => 'yes' === get_post_meta( $question->ID, '_random_order', true ),
		];

		$answer_feedback = get_post_meta( $question->ID, '_answer_feedback', true );
		if ( ! empty( $answer_feedback ) ) {
			$type_specific_properties['answer_feedback'] = $answer_feedback;
		}

		$correct_answers = $this->get_answers_array( $question, '_question_right_answer', true );
		$wrong_answers   = $this->get_answers_array( $question, '_question_wrong_answers', false );

		$answer_order       = get_post_meta( $question->ID, '_answer_order', true );
		$all_answers_sorted = Sensei()->question->get_answers_sorted( array_merge( $correct_answers, $wrong_answers ), $answer_order );

		$type_specific_properties['options'] = array_values( $all_answers_sorted );

		return $type_specific_properties;
	}

	/**
	 * Helper method which transforms the answers question meta to an associative array. This is the format that is expected by
	 * Sensei_Question::get_answers_sorted.
	 *
	 * @param WP_Post $question   The question post.
	 * @param string  $meta_key   The answers meta key.
	 * @param bool    $is_correct Whether the questions are correct.
	 *
	 * @see Sensei_Question::get_answers_sorted
	 *
	 * @return array The answers array.
	 */
	private function get_answers_array( WP_Post $question, string $meta_key, bool $is_correct ) : array {
		$answers = get_post_meta( $question->ID, $meta_key, true );

		if ( empty( $answers ) ) {
			return [];
		}

		if ( ! is_array( $answers ) ) {
			$answers = [ $answers ];
		}

		$result = [];
		foreach ( $answers as $correct_answer ) {
			$result[ Sensei()->lesson->get_answer_id( $correct_answer ) ] = [
				'label'   => $correct_answer,
				'correct' => $is_correct,
			];
		}

		return $result;
	}

	/**
	 * Schema for the endpoint.
	 *
	 * @return array Schema object.
	 */
	public function get_schema() : array {
		return [
			'definitions' => $this->get_question_definitions(),
			'type'        => 'object',
			'properties'  => [
				'options'   => [
					'type'       => 'object',
					'properties' => [
						'pass_required'         => [
							'type'        => 'boolean',
							'description' => 'Pass required to complete lesson',
							'default'     => false,
						],
						'quiz_passmark'         => [
							'type'        => 'integer',
							'description' => 'Score grade between 0 and 100 required to pass the quiz',
							'default'     => 100,
						],
						'auto_grade'            => [
							'type'        => 'boolean',
							'description' => 'Whether auto-grading should take place',
							'default'     => true,
						],
						'allow_retakes'         => [
							'type'        => 'boolean',
							'description' => 'Allow quizzes to be taken again',
							'default'     => true,
						],
						'show_questions'        => [
							'type'        => [ 'integer', 'null' ],
							'description' => 'Number of questions to show randomly',
							'default'     => null,
						],
						'random_question_order' => [
							'type'        => 'boolean',
							'description' => 'Show questions in a random order',
							'default'     => false,
						],
					],
				],
				'questions' => [
					'type'        => 'array',
					'description' => 'Questions in quiz',
					'items'       => [
						'anyOf' => [
							[
								'$ref' => '#/definitions/question_multiple_choice',
							],
							[
								'$ref' => '#/definitions/question_boolean',
							],
							[
								'$ref' => '#/definitions/question_gap_fill',
							],
							[
								'$ref' => '#/definitions/question_single_line',
							],
							[
								'$ref' => '#/definitions/question_multi_line',
							],
							[
								'$ref' => '#/definitions/question_file_upload',
							],
						],
					],
				],
			],
			'required'    => [
				'options',
				'questions',
			],
		];
	}

	/**
	 * Helper method which returns the question schema definitions.
	 *
	 * @return array The definitions
	 */
	private function get_question_definitions() : array {
		return [
			'question'                 => [
				'type'       => 'object',
				'properties' => [
					'id'          => [
						'type'        => 'integer',
						'description' => 'Question post ID',
					],
					'title'       => [
						'type'        => 'string',
						'description' => 'Question text',
					],
					'description' => [
						'type'        => 'string',
						'description' => 'Question description',
					],
					'grade'       => [
						'type'        => 'integer',
						'description' => 'Points this question is worth',
						'default'     => 1,
					],
					'shared'      => [
						'type'        => 'boolean',
						'description' => 'Whether the question has been added on other quizzes',
						'readOnly'    => false,
					],
					'categories'  => [
						'type'        => 'array',
						'description' => 'Category term IDs attached to the question',
						'items'       => [
							'type'        => 'integer',
							'description' => 'Term IDs',
						],
					],
				],
				'required'   => [
					'title',
				],
			],
			'question_multiple_choice' => [
				'allOf' => [
					[
						'$ref' => '#/definitions/question',
					],
					[
						'properties' => [
							'type'            => [
								'const' => 'multiple-choice',
							],
							'options'         => [
								'type'        => 'array',
								'description' => 'Options for the multiple choice',
								'items'       => [
									'type'       => 'object',
									'properties' => [
										'label'   => [
											'type'        => 'string',
											'description' => 'Label for answer option',
										],
										'correct' => [
											'type'        => 'boolean',
											'description' => 'Whether this answer is correct',
										],
									],
								],
							],
							'random_order'    => [
								'type'        => 'boolean',
								'description' => 'Should options be randomized when displayed to quiz takers',
								'default'     => false,
							],
							'answer_feedback' => [
								'type'        => 'string',
								'description' => 'Feedback to show quiz takers once quiz is submitted',
							],
						],
						'required'   => [
							'options',
						],
					],
				],
			],
			'question_boolean'         => [
				'allOf' => [
					[
						'$ref' => '#/definitions/question',
					],
					[
						'properties' => [
							'type'            => [
								'const' => 'boolean',
							],
							'answer'          => [
								'type'        => 'boolean',
								'description' => 'Correct answer for question',
							],
							'answer_feedback' => [
								'type'        => 'string',
								'description' => 'Feedback to show quiz takers once quiz is submitted',
							],
						],
						'required'   => [
							'answer',
						],
					],
				],
			],
			'question_gap_fill'        => [
				'allOf' => [
					[
						'$ref' => '#/definitions/question',
					],
					[
						'properties' => [
							'type'   => [
								'const' => 'gap-fill',
							],
							'before' => [
								'type'        => 'string',
								'description' => 'Text before the gap',
							],
							'gap'    => [
								'type'        => 'array',
								'description' => 'Gap text answers',
								'items'       => [
									'type'        => 'string',
									'description' => 'Gap answers',
								],
							],
							'after'  => [
								'type'        => 'string',
								'description' => 'Text after the gap',
							],
						],
					],
				],
			],
			'question_single_line'     => [
				'allOf' => [
					[
						'$ref' => '#/definitions/question',
					],
					[
						'properties' => [
							'type'          => [
								'const' => 'single-line',
							],
							'teacher_notes' => [
								'type'        => 'string',
								'description' => 'Teacher notes for grading',
							],
						],
					],
				],
			],
			'question_multi_line'      => [
				'allOf' => [
					[
						'$ref' => '#/definitions/question',
					],
					[
						'properties' => [
							'type'          => [
								'const' => 'multi-line',
							],
							'teacher_notes' => [
								'type'        => 'string',
								'description' => 'Teacher notes for grading',
							],
						],
					],
				],
			],
			'question_file_upload'     => [
				'allOf' => [
					[
						'$ref' => '#/definitions/question',
					],
					[
						'properties' => [
							'type'          => [
								'const' => 'file-upload',
							],
							'student_help'  => [
								'type'        => 'string',
								'description' => 'Description for student explaining what needs to be uploaded',
							],
							'teacher_notes' => [
								'type'        => 'string',
								'description' => 'Teacher notes for grading',
							],
						],
					],
				],
			],
		];
	}
}
