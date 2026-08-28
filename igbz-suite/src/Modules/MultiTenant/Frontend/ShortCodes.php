<?php
namespace IGBZ\Suite\Modules\MultiTenant\Frontend;

use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Modules\MultiTenant\Otp\OtpService;
use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Support\TenantScope;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront shortcodes: course catalogue and player, plan pricing table, BNPL calculator,
 * wallet balance badge and the phone/OTP login form.
 *
 * Every shortcode is self-contained so a tenant can drop it into any page builder.
 */
final class ShortCodes {

	public function register(): void {
		add_shortcode( 'igbz_courses', [ $this, 'courses' ] );
		add_shortcode( 'igbz_course', [ $this, 'course' ] );
		add_shortcode( 'igbz_plans', [ $this, 'plans' ] );
		add_shortcode( 'igbz_bnpl_calculator', [ $this, 'bnpl_calculator' ] );
		add_shortcode( 'igbz_wallet_balance', [ $this, 'wallet_balance' ] );
		add_shortcode( 'igbz_otp_login', [ $this, 'otp_login' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'wp_ajax_nopriv_igbz_otp', [ $this, 'ajax_otp' ] );
		add_action( 'wp_ajax_igbz_otp', [ $this, 'ajax_otp' ] );
		add_action( 'template_redirect', [ $this, 'maybe_stream_video' ] );
	}

	public function register_assets(): void {
		wp_register_style( 'igbz-front', IGBZ_URL . 'assets/css/front.css', [], IGBZ_VERSION );
		wp_register_script( 'igbz-front', IGBZ_URL . 'assets/js/front.js', [], IGBZ_VERSION, true );
		wp_localize_script(
			'igbz-front',
			'igbzFront',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'igbz_front' ),
				'i18n'    => [
					'sending'   => __( 'Sending…', 'igbz-suite' ),
					'sendCode'  => __( 'Send code', 'igbz-suite' ),
					'verifying' => __( 'Verifying…', 'igbz-suite' ),
					'copied'    => __( 'Copied!', 'igbz-suite' ),
				],
			]
		);
	}

	private function assets(): void {
		wp_enqueue_style( 'igbz-front' );
		wp_enqueue_script( 'igbz-front' );
	}

	// ------------------------------------------------------------- catalogue

	/** @param array<string,string>|string $atts */
	public function courses( $atts = [] ): string {
		$atts = shortcode_atts(
			[ 'limit' => 12, 'level' => '', 'columns' => 3 ],
			(array) $atts,
			'igbz_courses'
		);
		$this->assets();

		/** @var LmsService $lms */
		$lms     = igbz()->get( 'lms' );
		$courses = $lms->courses(
			[
				'tenant_id' => igbz()->tenancy()->id(),
				'published' => true,
				'limit'     => (int) $atts['limit'],
			]
		);

		if ( '' !== $atts['level'] ) {
			$courses = array_values( array_filter( $courses, static fn ( $c ) => $c['level'] === $atts['level'] ) );
		}

		if ( ! $courses ) {
			return '<p class="igbz-empty">' . esc_html__( 'No courses published yet.', 'igbz-suite' ) . '</p>';
		}

		ob_start();
		printf( '<div class="igbz-course-grid igbz-cols-%d">', (int) $atts['columns'] );
		foreach ( $courses as $course ) {
			echo '<article class="igbz-course-card">';
			if ( ! empty( $course['cover_url'] ) ) {
				printf( '<img src="%1$s" alt="%2$s" loading="lazy" />', esc_url( (string) $course['cover_url'] ), esc_attr( (string) $course['title'] ) );
			}
			printf( '<h3>%s</h3>', esc_html( (string) $course['title'] ) );
			if ( ! empty( $course['summary'] ) ) {
				printf( '<p>%s</p>', esc_html( wp_trim_words( (string) $course['summary'], 24 ) ) );
			}
			echo '<footer>';
			if ( (int) $course['duration_minutes'] > 0 ) {
				printf(
					'<span>%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: minutes */
							__( '%d min', 'igbz-suite' ),
							(int) $course['duration_minutes']
						)
					)
				);
			}
			$product_id = (int) $course['product_id'];
			$link       = $product_id > 0 ? get_permalink( $product_id ) : $this->course_url( (string) $course['slug'] );
			printf( '<a class="button" href="%1$s">%2$s</a>', esc_url( (string) $link ), esc_html__( 'View course', 'igbz-suite' ) );
			echo '</footer></article>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	/** Course player. Reads ?igbz_course=<slug> when no slug attribute is given. */
	public function course( $atts = [] ): string {
		$atts = shortcode_atts( [ 'slug' => '' ], (array) $atts, 'igbz_course' );
		$this->assets();

		$slug = '' !== $atts['slug'] ? sanitize_title( $atts['slug'] ) : '';
		if ( '' === $slug && isset( $_GET['igbz_course'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = sanitize_title( wp_unslash( $_GET['igbz_course'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === $slug ) {
			return '';
		}

		/** @var LmsService $lms */
		$lms    = igbz()->get( 'lms' );
		$course = $lms->course_by_slug( $slug, igbz()->tenancy()->id() );

		if ( ! $course || ! $course['is_published'] ) {
			return '<p class="igbz-empty">' . esc_html__( 'Course not found.', 'igbz-suite' ) . '</p>';
		}

		$user_id  = get_current_user_id();
		$enrolled = $user_id > 0 && $lms->is_enrolled( (int) $course['id'], $user_id );
		$lessons  = $lms->lessons( (int) $course['id'] );

		// Grading happens before any output: a submission redirects (post/redirect/get) so a
		// refresh cannot re-submit the same answers and burn a second attempt.
		$result = $enrolled
			? $this->maybe_grade_quiz( $lms, (int) $course['id'], $user_id, $this->course_url( (string) $course['slug'] ) )
			: null;

		ob_start();
		echo '<div class="igbz-course-player">';
		printf( '<h2>%s</h2>', esc_html( (string) $course['title'] ) );
		if ( ! empty( $course['description'] ) ) {
			echo '<div class="igbz-course-description">' . wp_kses_post( wpautop( (string) $course['description'] ) ) . '</div>';
		}

		if ( ! $enrolled ) {
			$product_id = (int) $course['product_id'];
			printf(
				'<p class="igbz-locked">%1$s %2$s</p>',
				esc_html__( 'You need access to view the lessons.', 'igbz-suite' ),
				$product_id > 0
					? sprintf(
						'<a class="button" href="%1$s">%2$s</a>',
						esc_url( (string) get_permalink( $product_id ) ),
						esc_html__( 'Enrol now', 'igbz-suite' )
					)
					: ''
			);
		}

		// Grouped by lesson_id up front so the loop below does not run a query per lesson;
		// lesson_id 0 means the quiz belongs to the course as a whole.
		$lesson_quizzes = [];
		foreach ( $lms->quizzes( (int) $course['id'] ) as $quiz ) {
			$lesson_quizzes[ (int) $quiz['lesson_id'] ][] = $quiz;
		}

		echo '<ol class="igbz-lesson-list">';
		foreach ( $lessons as $lesson ) {
			$open = $enrolled || (int) $lesson['is_free_preview'] === 1;
			printf( '<li class="%s">', esc_attr( $open ? 'igbz-open' : 'igbz-locked' ) );
			printf( '<strong>%s</strong>', esc_html( (string) $lesson['title'] ) );
			if ( (int) $lesson['duration_minutes'] > 0 ) {
				printf(
					' <small>%s</small>',
					esc_html(
						sprintf(
							/* translators: %d: minutes */
							__( '%d min', 'igbz-suite' ),
							(int) $lesson['duration_minutes']
						)
					)
				);
			}

			if ( $open && ! empty( $lesson['video_key'] ) ) {
				printf(
					'<video controls preload="none" src="%s"></video>',
					esc_url( $lms->signed_video_url( (string) $lesson['video_key'], $user_id ) )
				);
			}
			if ( $open && ! empty( $lesson['content'] ) ) {
				echo '<div class="igbz-lesson-body">' . wp_kses_post( wpautop( (string) $lesson['content'] ) ) . '</div>';
			}
			if ( $open && ! empty( $lesson['attachment_url'] ) ) {
				printf(
					'<a class="igbz-attachment" href="%1$s" download>%2$s</a>',
					esc_url( (string) $lesson['attachment_url'] ),
					esc_html__( 'Download attachment', 'igbz-suite' )
				);
			}
			// Gated on enrollment, not on $open: a free-preview lesson is a sample of the teaching,
			// not of the assessment, and submit_quiz() would refuse the answers anyway.
			$this->render_quizzes( $lms, $lesson_quizzes[ (int) $lesson['id'] ] ?? [], $user_id, $enrolled, $result );

			echo '</li>';
		}
		echo '</ol>';

		// Quizzes that belong to the course rather than to one lesson — the final exam.
		if ( ! empty( $lesson_quizzes[0] ) ) {
			echo '<div class="igbz-course-exam">';
			printf( '<h3>%s</h3>', esc_html__( 'Course assessment', 'igbz-suite' ) );
			$this->render_quizzes( $lms, $lesson_quizzes[0], $user_id, $enrolled, $result );
			echo '</div>';
		}

		$this->render_certificate( $lms, (int) $course['id'], $user_id, $enrolled );

		echo '</div>';

		return (string) ob_get_clean();
	}

	// ------------------------------------------------------------- quizzes

	/**
	 * Grade a submitted quiz, then redirect.
	 *
	 * The redirect is the point. Grading on POST and rendering the same request would mean a
	 * browser refresh re-posts the answers, and since every submission consumes an attempt the
	 * student would lose one to a stray F5. The outcome is parked in a transient keyed by user
	 * and quiz, read once on the way back.
	 *
	 * @param string $back Where to send the student afterwards. Passed in rather than derived from
	 *                     wp_get_referer(), which returns false when the referer matches the
	 *                     current URL — exactly the case for a form that posts to itself, and the
	 *                     reason the first version of this dropped everybody on the home page.
	 * @return array{quiz_id:int,score:int,passed:bool,remaining:int,error:string}|null
	 */
	private function maybe_grade_quiz( LmsService $lms, int $course_id, int $user_id, string $back ): ?array {
		$key = TenantScope::cache_key( 'igbz_quiz_result_' . $user_id );

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['igbz_quiz_id'] ) ) {
			$stored = get_transient( $key );
			if ( is_array( $stored ) ) {
				delete_transient( $key );
				return $stored;
			}
			return null;
		}

		$quiz_id = (int) $_POST['igbz_quiz_id']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$nonce = isset( $_POST['_igbz_quiz_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_igbz_quiz_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'igbz_submit_quiz_' . $quiz_id ) ) {
			$this->store_quiz_result( $key, $back, $quiz_id, 0, false, 0, __( 'Your session expired. Please try again.', 'igbz-suite' ) );
		}

		$quiz = $lms->quiz( $quiz_id );
		// The quiz must belong to the course being displayed, or a student enrolled on one course
		// could post an answer sheet for a quiz on another.
		if ( ! $quiz || (int) $quiz['course_id'] !== $course_id ) {
			$this->store_quiz_result( $key, $back, $quiz_id, 0, false, 0, __( 'Quiz not found.', 'igbz-suite' ) );
		}

		$answers = [];
		if ( isset( $_POST['igbz_answers'] ) && is_array( $_POST['igbz_answers'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// Sanitised by hand: an answer is either a scalar or a list of scalars, and
			// sanitize_text_field() on an array returns an empty string.
			foreach ( wp_unslash( $_POST['igbz_answers'] ) as $question => $given ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$question = sanitize_text_field( (string) $question );
				$answers[ $question ] = is_array( $given )
					? array_map( 'sanitize_text_field', array_map( 'strval', $given ) )
					: sanitize_text_field( (string) $given );
			}
		}

		try {
			$graded = $lms->submit_quiz( $quiz_id, $user_id, $answers );
		} catch ( \RuntimeException $e ) {
			$this->store_quiz_result( $key, $back, $quiz_id, 0, false, 0, $e->getMessage() );
			return null; // Unreachable: store_quiz_result() redirects.
		}

		$this->store_quiz_result(
			$key,
			$back,
			$quiz_id,
			(int) $graded['score'],
			(bool) $graded['passed'],
			(int) $graded['remaining_attempts'],
			''
		);

		return null; // Unreachable.
	}

	/** Park the outcome and bounce back to the player. Never returns. */
	private function store_quiz_result( string $key, string $back, int $quiz_id, int $score, bool $passed, int $remaining, string $error ): void {
		set_transient(
			$key,
			[
				'quiz_id'   => $quiz_id,
				'score'     => $score,
				'passed'    => $passed,
				'remaining' => $remaining,
				'error'     => $error,
			],
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( '' !== $back ? $back : home_url( '/' ) );
		exit;
	}

	/**
	 * @param array<int,array<string,mixed>> $quizzes
	 * @param array{quiz_id:int,score:int,passed:bool,remaining:int,error:string}|null $result
	 */
	private function render_quizzes( LmsService $lms, array $quizzes, int $user_id, bool $open, ?array $result ): void {
		foreach ( $quizzes as $quiz ) {
			if ( ! $open ) {
				// A locked lesson still says a quiz is there — it is part of what the course is
				// selling — but shows none of it.
				printf(
					'<p class="igbz-quiz-locked">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: quiz title */
							__( 'Quiz: %s (enrol to take it)', 'igbz-suite' ),
							(string) $quiz['title']
						)
					)
				);
				continue;
			}

			$this->render_quiz( $lms->quiz_for_user( $quiz, $user_id ), $result );
		}
	}

	/**
	 * @param array<string,mixed> $quiz
	 * @param array{quiz_id:int,score:int,passed:bool,remaining:int,error:string}|null $result
	 */
	private function render_quiz( array $quiz, ?array $result ): void {
		$quiz_id = (int) $quiz['id'];
		$mine    = $result && (int) $result['quiz_id'] === $quiz_id ? $result : null;

		echo '<div class="igbz-quiz">';
		printf( '<h4>%s</h4>', esc_html( (string) $quiz['title'] ) );

		printf(
			'<p class="igbz-quiz-rules">%s</p>',
			esc_html( $this->quiz_rules( $quiz ) )
		);

		if ( $mine && '' !== $mine['error'] ) {
			printf( '<p class="igbz-quiz-error">%s</p>', esc_html( (string) $mine['error'] ) );
		} elseif ( $mine ) {
			printf(
				'<p class="igbz-quiz-result %1$s">%2$s</p>',
				esc_attr( $mine['passed'] ? 'igbz-pass' : 'igbz-fail' ),
				esc_html(
					$mine['passed']
						? sprintf(
							/* translators: %d: score percentage */
							__( 'Passed with %d%%.', 'igbz-suite' ),
							(int) $mine['score']
						)
						: sprintf(
							/* translators: %d: score percentage */
							__( 'Scored %d%% — not a pass this time.', 'igbz-suite' ),
							(int) $mine['score']
						)
				)
			);
		} elseif ( null !== $quiz['best_score'] ) {
			printf(
				'<p class="igbz-quiz-result %1$s">%2$s</p>',
				esc_attr( $quiz['passed'] ? 'igbz-pass' : 'igbz-fail' ),
				esc_html(
					sprintf(
						/* translators: %d: score percentage */
						$quiz['passed'] ? __( 'Passed — best score %d%%.', 'igbz-suite' ) : __( 'Best score so far: %d%%.', 'igbz-suite' ),
						(int) $quiz['best_score']
					)
				)
			);
		}

		// A passed quiz is done; re-taking it can only make the record worse and would spend an
		// attempt for nothing.
		if ( $quiz['passed'] ) {
			echo '</div>';
			return;
		}

		if ( ! $quiz['can_attempt'] ) {
			printf(
				'<p class="igbz-quiz-exhausted">%s</p>',
				esc_html__( 'You have used every attempt on this quiz. Contact the instructor if you need another.', 'igbz-suite' )
			);
			echo '</div>';
			return;
		}

		if ( ! $quiz['questions'] ) {
			printf( '<p class="igbz-empty">%s</p>', esc_html__( 'This quiz has no questions yet.', 'igbz-suite' ) );
			echo '</div>';
			return;
		}

		echo '<form method="post" class="igbz-quiz-form">';
		wp_nonce_field( 'igbz_submit_quiz_' . $quiz_id, '_igbz_quiz_nonce' );
		printf( '<input type="hidden" name="igbz_quiz_id" value="%d" />', $quiz_id );

		foreach ( $quiz['questions'] as $index => $question ) {
			$name = 'igbz_answers[' . $question['id'] . ']' . ( $question['multiple'] ? '[]' : '' );

			echo '<fieldset class="igbz-quiz-question">';
			printf( '<legend>%1$d. %2$s</legend>', (int) $index + 1, esc_html( $question['question'] ) );

			foreach ( $question['options'] as $value => $label ) {
				$field_id = sprintf( 'igbz-q%1$d-%2$s-%3$d', $quiz_id, preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $question['id'] ), (int) $value );
				printf(
					'<label for="%1$s"><input type="%2$s" id="%1$s" name="%3$s" value="%4$d" /> %5$s</label>',
					esc_attr( $field_id ),
					$question['multiple'] ? 'checkbox' : 'radio',
					esc_attr( $name ),
					(int) $value,
					esc_html( $label )
				);
			}

			echo '</fieldset>';
		}

		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Submit answers', 'igbz-suite' ) );
		echo '</form></div>';
	}

	/** @param array<string,mixed> $quiz */
	private function quiz_rules( array $quiz ): string {
		$parts = [
			sprintf(
				/* translators: %d: percentage */
				__( 'Pass mark %d%%', 'igbz-suite' ),
				(int) $quiz['pass_score']
			),
		];

		$parts[] = LmsService::ATTEMPTS_UNLIMITED === $quiz['remaining_attempts']
			? __( 'unlimited attempts', 'igbz-suite' )
			: sprintf(
				/* translators: %d: number of attempts */
				_n( '%d attempt left', '%d attempts left', (int) $quiz['remaining_attempts'], 'igbz-suite' ),
				(int) $quiz['remaining_attempts']
			);

		if ( (int) $quiz['time_limit'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: minutes */
				__( '%d minute limit', 'igbz-suite' ),
				(int) $quiz['time_limit']
			);
		}

		return implode( ' · ', $parts );
	}

	// --------------------------------------------------------- certificate

	private function render_certificate( LmsService $lms, int $course_id, int $user_id, bool $enrolled ): void {
		if ( ! $enrolled || ! $lms->certificates_enabled() ) {
			return;
		}

		$enrollment = $lms->enrollment( $course_id, $user_id );
		if ( ! $enrollment ) {
			return;
		}

		$code = (string) $enrollment['certificate_code'];
		if ( '' === $code ) {
			// Re-check: the student may have just passed the last quiz, or finished the lessons
			// on a site where certificates were switched on after the fact.
			$code = $lms->maybe_issue_certificate( (int) $enrollment['id'] );
		}

		if ( '' === $code ) {
			return;
		}

		printf(
			'<div class="igbz-certificate-panel"><h3>%1$s</h3><p>%2$s</p><p><code>%3$s</code></p><p><a class="button" href="%4$s">%5$s</a></p></div>',
			esc_html__( 'Your certificate', 'igbz-suite' ),
			esc_html__( 'You have completed this course. Anyone can confirm the certificate at the address below.', 'igbz-suite' ),
			esc_html( $code ),
			esc_url( $this->certificate_url( $code ) ),
			esc_html__( 'View and verify', 'igbz-suite' )
		);
	}

	private function certificate_url( string $code ): string {
		$slug = trim( igbz()->settings()->string( 'lms.certificate_slug', 'certificate' ), '/' );
		return home_url( '/' . ( '' !== $slug ? $slug : 'certificate' ) . '/' . rawurlencode( $code ) );
	}

	private function course_url( string $slug ): string {
		$page_id = (int) igbz()->settings()->get( 'lms.course_page_id', 0 );
		$base    = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/' );
		return add_query_arg( 'igbz_course', $slug, $base ?: home_url( '/' ) );
	}

	/**
	 * Signed video responder: /?igbz_video=<key>&u=&e=&s=
	 *
	 * The key is resolved to a real URL through a filter so a tenant can point it at S3, ArvanCloud
	 * or a local uploads path without changing the plugin.
	 */
	public function maybe_stream_video(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- HMAC-signed URL.
		if ( empty( $_GET['igbz_video'] ) ) {
			return;
		}
		$key       = sanitize_text_field( wp_unslash( $_GET['igbz_video'] ) );
		$user_id   = isset( $_GET['u'] ) ? absint( wp_unslash( $_GET['u'] ) ) : 0;
		$expires   = isset( $_GET['e'] ) ? absint( wp_unslash( $_GET['e'] ) ) : 0;
		$signature = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/** @var LmsService $lms */
		$lms = igbz()->get( 'lms' );

		if ( $user_id !== get_current_user_id() || ! $lms->verify_video_signature( $key, $user_id, $expires, $signature ) ) {
			status_header( 403 );
			nocache_headers();
			exit;
		}

		$url = (string) apply_filters( 'igbz_lms_video_source', '', $key, $user_id );
		if ( '' === $url ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		nocache_headers();
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- storage host is set by the site owner.
		exit;
	}

	// ----------------------------------------------------------------- plans

	/** @param array<string,string>|string $atts */
	public function plans( $atts = [] ): string {
		$atts = shortcode_atts( [ 'highlight' => '' ], (array) $atts, 'igbz_plans' );
		$this->assets();

		/** @var PlanService $service */
		$service = igbz()->get( 'plans' );
		$plans   = $service->plans( true );

		if ( ! $plans ) {
			return '<p class="igbz-empty">' . esc_html__( 'No plans are available.', 'igbz-suite' ) . '</p>';
		}

		ob_start();
		echo '<div class="igbz-plan-grid">';
		foreach ( $plans as $plan ) {
			$features = json_decode( (string) ( $plan['features'] ?? '[]' ), true );
			$features = is_array( $features ) ? $features : [];

			printf(
				'<article class="igbz-plan-card %s">',
				esc_attr( $atts['highlight'] === $plan['slug'] ? 'igbz-featured' : '' )
			);
			printf( '<h3>%s</h3>', esc_html( (string) $plan['name'] ) );
			printf(
				'<div class="igbz-plan-price">%1$s<small>/%2$s</small></div>',
				wp_kses_post( wc_price( (float) $plan['price'] ) ),
				esc_html( $this->interval_label( (string) $plan['billing_interval'] ) )
			);
			if ( (int) $plan['trial_days'] > 0 ) {
				printf(
					'<p class="igbz-plan-trial">%s</p>',
					esc_html(
						sprintf(
							/* translators: %d: days */
							__( '%d-day free trial', 'igbz-suite' ),
							(int) $plan['trial_days']
						)
					)
				);
			}
			echo '<ul>';
			foreach ( $features as $label => $value ) {
				printf(
					'<li>%1$s%2$s</li>',
					esc_html( is_string( $label ) ? $this->feature_label( $label ) : '' ),
					esc_html( is_scalar( $value ) ? ': ' . $value : '' )
				);
			}
			echo '</ul>';
			printf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url( add_query_arg( 'igbz_plan', (string) $plan['slug'], wc_get_page_permalink( 'myaccount' ) ) ),
				esc_html__( 'Choose plan', 'igbz-suite' )
			);
			echo '</article>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	private function interval_label( string $interval ): string {
		return match ( $interval ) {
			'day'   => __( 'day', 'igbz-suite' ),
			'week'  => __( 'week', 'igbz-suite' ),
			'year'  => __( 'year', 'igbz-suite' ),
			default => __( 'month', 'igbz-suite' ),
		};
	}

	private function feature_label( string $key ): string {
		return ucwords( str_replace( [ '_', '.' ], ' ', $key ) );
	}

	// ------------------------------------------------------------------ bnpl

	/** @param array<string,string>|string $atts */
	public function bnpl_calculator( $atts = [] ): string {
		$atts = shortcode_atts( [ 'amount' => 0, 'counts' => '2,3,4,6,12' ], (array) $atts, 'igbz_bnpl_calculator' );
		$this->assets();

		$amount = (float) $atts['amount'];
		if ( $amount <= 0 && function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_the_ID() );
			$amount  = $product ? (float) $product->get_price() : 0;
		}
		if ( $amount <= 0 ) {
			return '';
		}

		/** @var BnplService $bnpl */
		$bnpl   = igbz()->get( 'bnpl' );
		$counts = array_filter( array_map( 'intval', explode( ',', (string) $atts['counts'] ) ) );

		ob_start();
		echo '<div class="igbz-bnpl-calculator">';
		printf( '<h4>%s</h4>', esc_html__( 'Pay in instalments', 'igbz-suite' ) );
		echo '<table><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Instalments', 'igbz-suite' ) );
		printf( '<th>%s</th>', esc_html__( 'Today', 'igbz-suite' ) );
		printf( '<th>%s</th>', esc_html__( 'Then', 'igbz-suite' ) );
		printf( '<th>%s</th>', esc_html__( 'Total', 'igbz-suite' ) );
		echo '</tr></thead><tbody>';

		foreach ( $counts as $count ) {
			$quote = $bnpl->quote( $amount, $count );
			$rest  = $quote['installments'];
			array_shift( $rest );
			$next = $rest ? (float) $rest[0]['amount'] : 0.0;

			printf(
				'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				$count,
				wp_kses_post( wc_price( (float) $quote['down_payment'] ) ),
				$next > 0
					? wp_kses_post(
						sprintf(
							/* translators: 1: amount, 2: count */
							esc_html__( '%1$s × %2$d', 'igbz-suite' ),
							wp_strip_all_tags( wc_price( $next ) ),
							count( $rest )
						)
					)
					: '—',
				wp_kses_post( wc_price( (float) $quote['total'] ) )
			);
		}

		echo '</tbody></table>';
		printf( '<small>%s</small>', esc_html__( 'Subject to credit approval at checkout.', 'igbz-suite' ) );
		echo '</div>';

		return (string) ob_get_clean();
	}

	// ---------------------------------------------------------------- wallet

	public function wallet_balance(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$balance = igbz()->get( 'wallet' )->balance( get_current_user_id(), igbz()->tenancy()->id() );

		return sprintf(
			'<a class="igbz-wallet-badge" href="%1$s">%2$s <strong>%3$s</strong></a>',
			esc_url( (string) wc_get_account_endpoint_url( AccountEndpoints::EP_WALLET ) ),
			esc_html__( 'Wallet', 'igbz-suite' ),
			wp_kses_post( wc_price( $balance ) )
		);
	}

	// ------------------------------------------------------------- otp login

	public function otp_login(): string {
		if ( is_user_logged_in() ) {
			return '';
		}
		$this->assets();

		ob_start();
		echo '<form class="igbz-otp-form" method="post">';
		printf( '<h3>%s</h3>', esc_html__( 'Sign in with your phone', 'igbz-suite' ) );
		printf(
			'<p><label for="igbz_otp_phone">%1$s</label><input type="tel" id="igbz_otp_phone" name="phone" inputmode="numeric" autocomplete="tel" required placeholder="09121234567" /></p>'
			. '<p class="igbz-otp-step-2" hidden><label for="igbz_otp_code">%2$s</label><input type="text" id="igbz_otp_code" name="code" inputmode="numeric" autocomplete="one-time-code" /></p>'
			. '<p><button type="button" class="button igbz-otp-send">%3$s</button> '
			. '<button type="button" class="button igbz-otp-verify" hidden>%4$s</button></p>'
			. '<p class="igbz-otp-message" role="status"></p>',
			esc_html__( 'Mobile number', 'igbz-suite' ),
			esc_html__( 'Verification code', 'igbz-suite' ),
			esc_html__( 'Send code', 'igbz-suite' ),
			esc_html__( 'Sign in', 'igbz-suite' )
		);
		echo '</form>';

		return (string) ob_get_clean();
	}

	/** admin-ajax handler for both OTP steps. */
	public function ajax_otp(): void {
		check_ajax_referer( 'igbz_front', 'nonce' );

		$step  = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		/** @var OtpService $otp */
		$otp = igbz()->get( 'otp' );

		if ( 'send' === $step ) {
			$result = $otp->send( $phone, OtpService::PURPOSE_LOGIN, igbz()->tenancy()->id() );
			if ( ! $result['ok'] ) {
				wp_send_json_error( [ 'message' => $result['error'], 'retryAfter' => $result['retry_after'] ] );
			}
			wp_send_json_success(
				[
					'message'   => __( 'We sent you a code.', 'igbz-suite' ),
					'expiresIn' => $result['expires_in'],
				]
			);
		}

		if ( 'verify' === $step ) {
			$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
			$result = $otp->verify( $phone, $code, OtpService::PURPOSE_LOGIN );

			if ( ! $result['ok'] ) {
				wp_send_json_error( [ 'message' => $result['error'] ] );
			}
			if ( (int) $result['user_id'] <= 0 ) {
				wp_send_json_error( [ 'message' => __( 'The account could not be created.', 'igbz-suite' ) ] );
			}

			$otp->login( (int) $result['user_id'] );
			wp_send_json_success( [ 'redirect' => wc_get_page_permalink( 'myaccount' ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'Unknown step.', 'igbz-suite' ) ] );
	}
}
