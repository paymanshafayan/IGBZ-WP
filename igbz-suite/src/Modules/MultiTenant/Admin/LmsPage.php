<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Courses, lessons, quizzes and enrollments. */
final class LmsPage {

	public const SLUG = 'igbz-courses';

	private const PER_PAGE = 20;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 15 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Courses', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_LMS );
	}

	private function lms(): LmsService {
		return igbz()->get( 'lms' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$course_id = isset( $_GET['course'] ) ? (int) $_GET['course'] : 0;
		$quiz_id   = isset( $_GET['quiz'] ) ? (int) $_GET['quiz'] : 0;
		$new       = isset( $_GET['new'] );
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		// phpcs:enable

		View::open(
			__( 'Courses', 'igbz-suite' ),
			__( 'A course can be attached to a WooCommerce product: buying that product enrolls the customer automatically. Video keys are served through signed, expiring URLs.', 'igbz-suite' )
		);

		if ( $quiz_id ) {
			$this->render_quiz_results( $course_id, $quiz_id );
			View::close();
			return;
		}

		if ( $course_id || $new ) {
			$this->render_course_editor( $course_id );
			View::close();
			return;
		}

		$this->render_list( $search, $paged );
		View::close();
	}

	private function render_list( string $search, int $paged ): void {
		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( Menu::url( self::SLUG, [ 'new' => 1 ] ) ),
			esc_html__( 'Add course', 'igbz-suite' )
		);

		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		printf(
			'<input type="search" name="s" value="%1$s" placeholder="%2$s" /> ',
			esc_attr( $search ),
			esc_attr__( 'Search courses', 'igbz-suite' )
		);
		submit_button( __( 'Search', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';

		$db     = igbz()->db();
		$total  = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'courses' ) );
		$rows   = $this->lms()->courses(
			[
				'search' => $search,
				'limit'  => self::PER_PAGE,
				'offset' => ( $paged - 1 ) * self::PER_PAGE,
			]
		);
		$display = [];

		foreach ( $rows as $course ) {
			$id        = (int) $course['id'];
			$lessons   = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'lessons' ) . ' WHERE course_id = %d', $id );
			$students  = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'enrollments' ) . ' WHERE course_id = %d', $id );
			$display[] = [
				'title'     => sprintf(
					'<a href="%1$s"><strong>%2$s</strong></a><br /><code>%3$s</code>',
					esc_url( Menu::url( self::SLUG, [ 'course' => $id ] ) ),
					esc_html( (string) $course['title'] ),
					esc_html( (string) $course['slug'] )
				),
				'product'   => $course['product_id']
					? sprintf( '<a href="%1$s">#%2$d</a>', esc_url( get_edit_post_link( (int) $course['product_id'] ) ?: '#' ), (int) $course['product_id'] )
					: '—',
				'level'     => esc_html( (string) $course['level'] ),
				'lessons'   => esc_html( (string) $lessons ),
				'students'  => esc_html( (string) $students ),
				'published' => View::status_pill( $course['is_published'] ? 'ok' : 'warn' ),
				'actions'   => sprintf(
					'<a class="button button-small" href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'delete_course' => $id ] ), 'igbz_lms_action' ) ),
					esc_js( __( 'Delete this course, its lessons and enrollments?', 'igbz-suite' ) ),
					esc_html__( 'Delete', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'title'     => __( 'Course', 'igbz-suite' ),
				'product'   => __( 'Product', 'igbz-suite' ),
				'level'     => __( 'Level', 'igbz-suite' ),
				'lessons'   => __( 'Lessons', 'igbz-suite' ),
				'students'  => __( 'Students', 'igbz-suite' ),
				'published' => __( 'Published', 'igbz-suite' ),
				'actions'   => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No courses yet.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 's' => $search ] );
	}

	private function render_course_editor( int $course_id ): void {
		$course = $course_id ? $this->lms()->course( $course_id ) : null;
		if ( $course_id && ! $course ) {
			View::notice( __( 'Course not found.', 'igbz-suite' ), 'error' );
			return;
		}

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG ) ),
			esc_html__( 'Back to courses', 'igbz-suite' )
		);

		echo '<form method="post">';
		wp_nonce_field( 'igbz_save_course' );
		printf( '<input type="hidden" name="igbz_action" value="save_course" /><input type="hidden" name="course_id" value="%d" />', $course_id );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->text_row( 'title', __( 'Title', 'igbz-suite' ), (string) ( $course['title'] ?? '' ), true );
		$this->text_row( 'slug', __( 'Slug', 'igbz-suite' ), (string) ( $course['slug'] ?? '' ) );
		$this->text_row( 'tenant_id', __( 'Tenant id', 'igbz-suite' ), (string) ( $course['tenant_id'] ?? 0 ), false, 'number' );

		echo '<tr><th scope="row">' . esc_html__( 'WooCommerce product', 'igbz-suite' ) . '</th><td>';
		$this->product_dropdown( (int) ( $course['product_id'] ?? 0 ) );
		echo '<p class="description">' . esc_html__( 'Completing an order containing this product enrolls the buyer.', 'igbz-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Instructor', 'igbz-suite' ) . '</th><td>';
		wp_dropdown_users(
			[
				'name'     => 'instructor_user_id',
				'selected' => (int) ( $course['instructor_user_id'] ?? get_current_user_id() ),
				'number'   => 200,
			]
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Level', 'igbz-suite' ) . '</th><td><select name="level">';
		foreach (
			[
				'beginner'     => __( 'Beginner', 'igbz-suite' ),
				'intermediate' => __( 'Intermediate', 'igbz-suite' ),
				'advanced'     => __( 'Advanced', 'igbz-suite' ),
			] as $value => $label
		) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) ( $course['level'] ?? 'beginner' ), $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		$this->text_row( 'duration_minutes', __( 'Duration (minutes)', 'igbz-suite' ), (string) ( $course['duration_minutes'] ?? 0 ), false, 'number' );
		$this->text_row( 'pass_score', __( 'Pass score', 'igbz-suite' ), (string) ( $course['pass_score'] ?? 60 ), false, 'number' );
		$this->text_row( 'cover_url', __( 'Cover image URL', 'igbz-suite' ), (string) ( $course['cover_url'] ?? '' ), false, 'url' );

		printf(
			'<tr><th scope="row">%1$s</th><td><textarea name="summary" rows="2" class="large-text">%2$s</textarea></td></tr>',
			esc_html__( 'Summary', 'igbz-suite' ),
			esc_textarea( (string) ( $course['summary'] ?? '' ) )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><textarea name="description" rows="6" class="large-text">%2$s</textarea></td></tr>',
			esc_html__( 'Description', 'igbz-suite' ),
			esc_textarea( (string) ( $course['description'] ?? '' ) )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="certificate_enabled" value="1" %2$s /> %3$s</label><br /><label><input type="checkbox" name="is_published" value="1" %4$s /> %5$s</label></td></tr>',
			esc_html__( 'Flags', 'igbz-suite' ),
			checked( (bool) ( $course['certificate_enabled'] ?? false ), true, false ),
			esc_html__( 'Issue a certificate on completion', 'igbz-suite' ),
			checked( (bool) ( $course['is_published'] ?? false ), true, false ),
			esc_html__( 'Published', 'igbz-suite' )
		);

		echo '</tbody></table>';
		submit_button( $course ? __( 'Update course', 'igbz-suite' ) : __( 'Create course', 'igbz-suite' ) );
		echo '</form>';

		if ( ! $course_id ) {
			return;
		}

		$this->render_lessons( $course_id, (int) ( $course['tenant_id'] ?? 0 ) );
		$this->render_quizzes( $course_id, (int) ( $course['tenant_id'] ?? 0 ) );
		$this->render_enrollments( $course_id );
	}

	private function render_lessons( int $course_id, int $tenant_id ): void {
		$rows = [];
		foreach ( $this->lms()->lessons( $course_id ) as $lesson ) {
			$rows[] = [
				'order'    => esc_html( (string) $lesson['sort_order'] ),
				'title'    => esc_html( (string) $lesson['title'] ),
				'video'    => esc_html( (string) $lesson['video_key'] ?: '—' ),
				'duration' => esc_html( (string) $lesson['duration_minutes'] ),
				'preview'  => $lesson['is_free_preview'] ? esc_html__( 'free', 'igbz-suite' ) : '—',
				'actions'  => sprintf(
					'<a class="button button-small" href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
					esc_url(
						wp_nonce_url(
							Menu::url( self::SLUG, [ 'course' => $course_id, 'delete_lesson' => (int) $lesson['id'] ] ),
							'igbz_lms_action'
						)
					),
					esc_js( __( 'Delete this lesson?', 'igbz-suite' ) ),
					esc_html__( 'Delete', 'igbz-suite' )
				),
			];
		}

		echo '<h2>' . esc_html__( 'Lessons', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'order'    => __( '#', 'igbz-suite' ),
				'title'    => __( 'Title', 'igbz-suite' ),
				'video'    => __( 'Video key', 'igbz-suite' ),
				'duration' => __( 'Minutes', 'igbz-suite' ),
				'preview'  => __( 'Preview', 'igbz-suite' ),
				'actions'  => __( 'Actions', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'This course has no lessons yet.', 'igbz-suite' )
		);

		echo '<form method="post">';
		wp_nonce_field( 'igbz_save_lesson' );
		printf(
			'<input type="hidden" name="igbz_action" value="save_lesson" /><input type="hidden" name="course_id" value="%1$d" /><input type="hidden" name="tenant_id" value="%2$d" />',
			$course_id,
			$tenant_id
		);
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->text_row( 'title', __( 'Lesson title', 'igbz-suite' ), '', true );
		$this->text_row( 'video_key', __( 'Video key', 'igbz-suite' ), '' );
		$this->text_row( 'attachment_url', __( 'Attachment URL', 'igbz-suite' ), '', false, 'url' );
		$this->text_row( 'duration_minutes', __( 'Minutes', 'igbz-suite' ), '0', false, 'number' );
		$this->text_row( 'sort_order', __( 'Sort order', 'igbz-suite' ), '0', false, 'number' );
		printf(
			'<tr><th scope="row">%1$s</th><td><textarea name="content" rows="4" class="large-text"></textarea></td></tr>',
			esc_html__( 'Content', 'igbz-suite' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="is_free_preview" value="1" /> %2$s</label></td></tr>',
			esc_html__( 'Free preview', 'igbz-suite' ),
			esc_html__( 'Visible without enrollment', 'igbz-suite' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Add lesson', 'igbz-suite' ), 'secondary' );
		echo '</form>';
	}

	private function render_quizzes( int $course_id, int $tenant_id ): void {
		$db   = igbz()->db();
		$lms  = $this->lms();
		$rows = $lms->quizzes( $course_id );

		$display = [];
		foreach ( $rows as $quiz ) {
			$id        = (int) $quiz['id'];
			$questions = (array) json_decode( (string) $quiz['questions'], true );
			$taken     = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'quiz_attempts' ) . ' WHERE quiz_id = %d', $id );
			$passed    = (int) $db->scalar( 'SELECT COUNT(DISTINCT user_id) FROM ' . $db->table( 'quiz_attempts' ) . ' WHERE quiz_id = %d AND passed = 1', $id );
			$average   = (float) $db->scalar( 'SELECT AVG(score) FROM ' . $db->table( 'quiz_attempts' ) . ' WHERE quiz_id = %d', $id );

			// The effective figures, not the raw column: the site settings can lower a quiz's
			// max_attempts and fill in a missing pass score, and the admin should see what
			// students will actually get.
			$display[] = [
				'title'     => sprintf(
					'<strong>%1$s</strong>%2$s',
					esc_html( (string) $quiz['title'] ),
					$quiz['lesson_id']
						? '<br /><small>' . esc_html( sprintf( /* translators: %d: lesson id */ __( 'Lesson #%d', 'igbz-suite' ), (int) $quiz['lesson_id'] ) ) . '</small>'
						: '<br /><small>' . esc_html__( 'Whole course', 'igbz-suite' ) . '</small>'
				),
				'questions' => esc_html( (string) count( $questions ) ),
				'pass'      => esc_html( $lms->pass_score( $quiz ) . '%' ),
				'attempts'  => esc_html(
					LmsService::ATTEMPTS_UNLIMITED === $lms->max_attempts( $quiz )
						? __( 'Unlimited', 'igbz-suite' )
						: (string) $lms->max_attempts( $quiz )
				),
				'limit'     => esc_html( $quiz['time_limit_minutes'] ? (string) $quiz['time_limit_minutes'] : '—' ),
				'results'   => $taken > 0
					? esc_html(
						sprintf(
							/* translators: 1: attempts, 2: students who passed, 3: average score */
							__( '%1$d attempts · %2$d passed · avg %3$d%%', 'igbz-suite' ),
							$taken,
							$passed,
							(int) round( $average )
						)
					)
					: '<span class="igbz-empty">' . esc_html__( 'Not attempted yet', 'igbz-suite' ) . '</span>',
				'actions'   => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s" onclick="return confirm(\'%4$s\')">%5$s</a>',
					esc_url( Menu::url( self::SLUG, [ 'course' => $course_id, 'quiz' => $id ] ) ),
					esc_html__( 'Results', 'igbz-suite' ),
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'course' => $course_id, 'delete_quiz' => $id ] ), 'igbz_lms_action' ) ),
					esc_js( __( 'Delete this quiz and every attempt at it?', 'igbz-suite' ) ),
					esc_html__( 'Delete', 'igbz-suite' )
				),
			];
		}

		echo '<h2>' . esc_html__( 'Quizzes', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'title'     => __( 'Quiz', 'igbz-suite' ),
				'questions' => __( 'Questions', 'igbz-suite' ),
				'pass'      => __( 'Pass score', 'igbz-suite' ),
				'attempts'  => __( 'Max attempts', 'igbz-suite' ),
				'limit'     => __( 'Time limit', 'igbz-suite' ),
				'results'   => __( 'Results', 'igbz-suite' ),
				'actions'   => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No quizzes on this course.', 'igbz-suite' )
		);

		echo '<form method="post">';
		wp_nonce_field( 'igbz_save_quiz' );
		printf(
			'<input type="hidden" name="igbz_action" value="save_quiz" /><input type="hidden" name="course_id" value="%1$d" /><input type="hidden" name="tenant_id" value="%2$d" />',
			$course_id,
			$tenant_id
		);
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->text_row( 'title', __( 'Quiz title', 'igbz-suite' ), '', true );

		echo '<tr><th scope="row">' . esc_html__( 'Attached to', 'igbz-suite' ) . '</th><td><select name="lesson_id">';
		printf( '<option value="0">%s</option>', esc_html__( '— the whole course —', 'igbz-suite' ) );
		foreach ( $this->lms()->lessons( $course_id ) as $lesson ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $lesson['id'], esc_html( (string) $lesson['title'] ) );
		}
		echo '</select><p class="description">'
			. esc_html__( 'A quiz attached to a lesson appears under it; one attached to the course appears at the end as the final assessment.', 'igbz-suite' )
			. '</p></td></tr>';

		$this->text_row( 'pass_score', __( 'Pass score', 'igbz-suite' ), '60', false, 'number' );
		$this->text_row( 'max_attempts', __( 'Max attempts', 'igbz-suite' ), '3', false, 'number' );
		$this->text_row( 'time_limit_minutes', __( 'Time limit (minutes)', 'igbz-suite' ), '0', false, 'number' );
		printf(
			'<tr><th scope="row">%1$s</th><td><textarea name="questions" rows="8" class="large-text code" placeholder="%2$s"></textarea><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Questions (JSON)', 'igbz-suite' ),
			esc_attr( '[{"q":"...","options":["a","b"],"answer":0}]' ),
			esc_html__( 'Answers stay on the server; only the question text and options are ever sent to the browser.', 'igbz-suite' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Add quiz', 'igbz-suite' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Every attempt at one quiz, newest first.
	 *
	 * This is the half of the feature that was missing entirely: quizzes could be written and,
	 * once the learner surfaces existed, taken — but nobody could see the answers. An instructor
	 * needs the per-question breakdown to tell "the class failed" from "question 4 is wrong".
	 */
	private function render_quiz_results( int $course_id, int $quiz_id ): void {
		$db   = igbz()->db();
		$lms  = $this->lms();
		$quiz = $lms->quiz( $quiz_id );

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG, [ 'course' => $course_id ] ) ),
			esc_html__( 'Back to the course', 'igbz-suite' )
		);

		if ( ! $quiz ) {
			View::notice( __( 'Quiz not found.', 'igbz-suite' ), 'error' );
			return;
		}

		printf( '<h2>%s</h2>', esc_html( (string) $quiz['title'] ) );

		$questions = $lms->questions_for_client( $quiz );
		$attempts  = $db->results(
			'SELECT * FROM ' . $db->table( 'quiz_attempts' ) . ' WHERE quiz_id = %d ORDER BY id DESC LIMIT 200',
			$quiz_id
		);

		// Per-question difficulty, computed from the stored answer sheets against the stored key.
		$key   = (array) json_decode( (string) $quiz['questions'], true );
		$stats = [];
		foreach ( $questions as $index => $question ) {
			$stats[ $question['id'] ] = [ 'label' => $question['question'], 'correct' => 0, 'index' => $index ];
		}

		$display = [];
		foreach ( $attempts as $attempt ) {
			$answers = (array) json_decode( (string) $attempt['answers'], true );

			foreach ( $key as $index => $question ) {
				$qid = (string) ( $question['id'] ?? $index );
				if ( ! isset( $stats[ $qid ] ) ) {
					continue;
				}
				$given    = $answers[ $qid ] ?? ( $answers[ $index ] ?? null );
				$expected = $question['answer'] ?? null;

				if ( is_array( $expected ) ) {
					$given_set    = array_map( 'strval', (array) $given );
					$expected_set = array_map( 'strval', $expected );
					sort( $given_set );
					sort( $expected_set );
					$right = $given_set === $expected_set;
				} else {
					$right = null !== $given && (string) $given === (string) $expected;
				}

				if ( $right ) {
					++$stats[ $qid ]['correct'];
				}
			}

			$user      = get_userdata( (int) $attempt['user_id'] );
			$display[] = [
				'user'   => esc_html( $user ? $user->display_name : '#' . $attempt['user_id'] ),
				'score'  => esc_html( $attempt['score'] . '%' ),
				'passed' => View::status_pill( $attempt['passed'] ? 'ok' : 'warn' ),
				'when'   => esc_html( (string) ( $attempt['finished_at'] ?: $attempt['started_at'] ) ),
			];
		}

		$total = count( $attempts );

		echo '<h3>' . esc_html__( 'Question breakdown', 'igbz-suite' ) . '</h3>';
		$breakdown = [];
		foreach ( $stats as $stat ) {
			$breakdown[] = [
				'question' => esc_html( sprintf( '%d. %s', (int) $stat['index'] + 1, $stat['label'] ) ),
				'correct'  => esc_html(
					$total > 0
						? sprintf(
							/* translators: 1: number correct, 2: total attempts, 3: percentage */
							__( '%1$d of %2$d (%3$d%%)', 'igbz-suite' ),
							(int) $stat['correct'],
							$total,
							(int) round( $stat['correct'] / $total * 100 )
						)
						: '—'
				),
			];
		}

		View::table(
			[
				'question' => __( 'Question', 'igbz-suite' ),
				'correct'  => __( 'Answered correctly', 'igbz-suite' ),
			],
			$breakdown,
			static fn ( array $row, string $col ): string => (string) $row[ $col ],
			__( 'This quiz has no questions.', 'igbz-suite' )
		);

		echo '<h3>' . esc_html__( 'Attempts', 'igbz-suite' ) . '</h3>';
		View::table(
			[
				'user'   => __( 'Student', 'igbz-suite' ),
				'score'  => __( 'Score', 'igbz-suite' ),
				'passed' => __( 'Passed', 'igbz-suite' ),
				'when'   => __( 'Taken', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $col ): string => (string) $row[ $col ],
			__( 'Nobody has taken this quiz yet.', 'igbz-suite' )
		);
	}

	private function render_enrollments( int $course_id ): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT * FROM ' . $db->table( 'enrollments' ) . ' WHERE course_id = %d ORDER BY id DESC LIMIT 50',
			$course_id
		);

		$slug = trim( igbz()->settings()->string( 'lms.certificate_slug', 'certificate' ), '/' );
		$slug = '' !== $slug ? $slug : 'certificate';

		$display = [];
		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			$code = (string) $row['certificate_code'];

			$display[] = [
				'user'        => esc_html( $user ? $user->display_name : '#' . $row['user_id'] ),
				'progress'    => esc_html( $row['progress_percent'] . '%' ),
				'completed'   => esc_html( (string) ( $row['completed_at'] ?? '—' ) ),
				'certificate' => '' !== $code
					? sprintf(
						'<a href="%1$s" target="_blank" rel="noreferrer noopener"><code>%2$s</code></a>',
						esc_url( home_url( '/' . $slug . '/' . rawurlencode( $code ) ) ),
						esc_html( $code )
					)
					: '—',
				'expires'     => esc_html( (string) ( $row['expires_at'] ?? '—' ) ),
				'created'     => esc_html( (string) $row['created_at'] ),
				'actions'     => sprintf(
					'<a class="button button-small" href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'course' => $course_id, 'revoke' => (int) $row['id'] ] ), 'igbz_lms_action' ) ),
					esc_js( __( 'Remove this student\'s access to the course?', 'igbz-suite' ) ),
					esc_html__( 'Revoke', 'igbz-suite' )
				),
			];
		}

		echo '<h2>' . esc_html__( 'Students', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'user'        => __( 'Student', 'igbz-suite' ),
				'progress'    => __( 'Progress', 'igbz-suite' ),
				'completed'   => __( 'Completed', 'igbz-suite' ),
				'certificate' => __( 'Certificate', 'igbz-suite' ),
				'expires'     => __( 'Access until', 'igbz-suite' ),
				'created'     => __( 'Enrolled', 'igbz-suite' ),
				'actions'     => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'Nobody is enrolled yet.', 'igbz-suite' )
		);

		echo '<form method="post" style="margin-top:8px">';
		wp_nonce_field( 'igbz_enroll_user' );
		printf( '<input type="hidden" name="igbz_action" value="enroll_user" /><input type="hidden" name="course_id" value="%d" />', $course_id );
		wp_dropdown_users( [ 'name' => 'user_id', 'number' => 200 ] );
		echo ' ';
		submit_button( __( 'Enroll manually', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';
	}

	private function product_dropdown( int $selected ): void {
		echo '<select name="product_id">';
		printf( '<option value="0">%s</option>', esc_html__( '— none —', 'igbz-suite' ) );

		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products( [ 'limit' => 200, 'status' => 'publish', 'return' => 'objects' ] );
			foreach ( $products as $product ) {
				printf(
					'<option value="%1$d" %2$s>%3$s</option>',
					$product->get_id(),
					selected( $selected, $product->get_id(), false ),
					esc_html( $product->get_name() )
				);
			}
		}

		echo '</select>';
	}

	private function text_row( string $name, string $label, string $value, bool $required = false, string $type = 'text' ): void {
		printf(
			'<tr><th scope="row"><label for="igbz_%1$s">%2$s</label></th><td><input type="%3$s" id="igbz_%1$s" name="%1$s" value="%4$s" class="regular-text" %5$s /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$required ? 'required' : ''
		);
	}

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_LMS );

		$action = isset( $_POST['igbz_action'] ) ? sanitize_key( (string) $_POST['igbz_action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$text   = static fn ( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$int    = static fn ( string $key ): int => isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'save_course':
				check_admin_referer( 'igbz_save_course' );
				$id = $this->lms()->save_course(
					[
						'title'               => $text( 'title' ),
						'slug'                => $text( 'slug' ),
						'tenant_id'           => $int( 'tenant_id' ),
						'product_id'          => $int( 'product_id' ),
						'instructor_user_id'  => $int( 'instructor_user_id' ),
						'level'               => $text( 'level' ),
						'duration_minutes'    => $int( 'duration_minutes' ),
						'pass_score'          => $int( 'pass_score' ),
						'cover_url'           => $text( 'cover_url' ),
						'summary'             => isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : '',
						'description'         => isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '',
						'certificate_enabled' => ! empty( $_POST['certificate_enabled'] ),
						'is_published'        => ! empty( $_POST['is_published'] ),
					],
					$int( 'course_id' )
				);
				View::notice( __( 'Course saved.', 'igbz-suite' ) );
				if ( ! $int( 'course_id' ) ) {
					printf(
						'<p><a class="button" href="%1$s">%2$s</a></p>',
						esc_url( Menu::url( self::SLUG, [ 'course' => $id ] ) ),
						esc_html__( 'Open the new course', 'igbz-suite' )
					);
				}
				break;

			case 'save_lesson':
				check_admin_referer( 'igbz_save_lesson' );
				$this->lms()->save_lesson(
					[
						'course_id'        => $int( 'course_id' ),
						'tenant_id'        => $int( 'tenant_id' ),
						'title'            => $text( 'title' ),
						'video_key'        => $text( 'video_key' ),
						'attachment_url'   => $text( 'attachment_url' ),
						'duration_minutes' => $int( 'duration_minutes' ),
						'sort_order'       => $int( 'sort_order' ),
						'content'          => isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '',
						'is_free_preview'  => ! empty( $_POST['is_free_preview'] ),
					]
				);
				View::notice( __( 'Lesson added.', 'igbz-suite' ) );
				break;

			case 'save_quiz':
				check_admin_referer( 'igbz_save_quiz' );
				$questions = isset( $_POST['questions'] )
					? json_decode( wp_unslash( (string) $_POST['questions'] ), true )
					: [];
				if ( ! is_array( $questions ) ) {
					View::notice( __( 'The questions field is not valid JSON.', 'igbz-suite' ), 'error' );
					break;
				}
				$this->lms()->save_quiz(
					[
						'course_id'          => $int( 'course_id' ),
						'lesson_id'          => $int( 'lesson_id' ),
						'tenant_id'          => $int( 'tenant_id' ),
						'title'              => $text( 'title' ),
						'questions'          => $questions,
						'pass_score'         => $int( 'pass_score' ),
						'max_attempts'       => $int( 'max_attempts' ),
						'time_limit_minutes' => $int( 'time_limit_minutes' ),
					]
				);
				View::notice( __( 'Quiz added.', 'igbz-suite' ) );
				break;

			case 'enroll_user':
				check_admin_referer( 'igbz_enroll_user' );
				$this->lms()->enroll( $int( 'course_id' ), $int( 'user_id' ) );
				View::notice( __( 'Student enrolled.', 'igbz-suite' ) );
				break;
		}
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$actions = [ 'delete_course', 'delete_lesson', 'delete_quiz', 'revoke' ];
		$present = false;
		foreach ( $actions as $action ) {
			if ( isset( $_GET[ $action ] ) ) {
				$present = true;
				break;
			}
		}
		if ( ! $present ) {
			return;
		}

		check_admin_referer( 'igbz_lms_action' );
		Capabilities::require( Capabilities::MANAGE_LMS );

		if ( isset( $_GET['delete_course'] ) ) {
			$this->lms()->delete_course( (int) $_GET['delete_course'] );
			View::notice( __( 'Course deleted.', 'igbz-suite' ) );
		}
		if ( isset( $_GET['delete_lesson'] ) ) {
			$this->lms()->delete_lesson( (int) $_GET['delete_lesson'] );
			View::notice( __( 'Lesson deleted.', 'igbz-suite' ) );
		}
		if ( isset( $_GET['delete_quiz'] ) ) {
			$quiz_id = (int) $_GET['delete_quiz'];
			$db      = igbz()->db();
			// Ownership first: the delete doubles as the tenant check, so a quiz id from another
			// tenant simply does not exist for us. Attempts go only after the quiz we own is gone.
			if ( $db->delete( 'quizzes', [ 'id' => $quiz_id, 'tenant_id' => igbz()->tenancy()->id() ] ) ) {
				$db->delete( 'quiz_attempts', [ 'quiz_id' => $quiz_id, 'tenant_id' => igbz()->tenancy()->id() ] );
				View::notice( __( 'Quiz deleted.', 'igbz-suite' ) );
			} else {
				View::notice( __( 'That quiz does not exist here.', 'igbz-suite' ), 'error' );
			}
		}
		if ( isset( $_GET['revoke'] ) ) {
			$enrollment_id = (int) $_GET['revoke'];
			$db            = igbz()->db();
			$row           = $db->row( 'SELECT course_id, user_id FROM ' . $db->table( 'enrollments' ) . ' WHERE id = %d AND tenant_id = %d', $enrollment_id, igbz()->tenancy()->id() );
			if ( $row && $this->lms()->unenroll( (int) $row['course_id'], (int) $row['user_id'] ) ) {
				View::notice( __( 'Access revoked.', 'igbz-suite' ) );
			} else {
				View::notice( __( 'That enrolment no longer exists.', 'igbz-suite' ), 'error' );
			}
		}
		// phpcs:enable
	}
}
