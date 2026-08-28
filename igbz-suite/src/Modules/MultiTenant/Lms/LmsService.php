<?php
namespace IGBZ\Suite\Modules\MultiTenant\Lms;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Courses, lessons, enrollment, progress, quizzes and certificates.
 * Enrollment is granted automatically when a WooCommerce order containing a linked product completes.
 */
final class LmsService {

	/** Returned by attempt counters when a quiz may be retaken for ever. */
	public const ATTEMPTS_UNLIMITED = -1;

	public function __construct( private Db $db ) {}

	// -------------------------------------------------------------- courses

	/** @return array<string,mixed>|null */
	public function course( int $id, ?int $tenant_id = null ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'courses' ) . ' WHERE id = %d AND tenant_id = %d',
			$id,
			$this->tenant( $tenant_id )
		);
	}

	/** @return array<string,mixed>|null */
	public function course_by_slug( string $slug, int $tenant_id = 0 ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'courses' ) . ' WHERE slug = %s AND tenant_id = %d',
			$slug,
			$tenant_id
		);
	}

	/** @return array<string,mixed>|null */
	public function course_by_product( int $product_id, ?int $tenant_id = null ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'courses' ) . ' WHERE product_id = %d AND tenant_id = %d',
			$product_id,
			$this->tenant( $tenant_id )
		);
	}

	/**
	 * Tenant scope for object reads (phase 07): null means "the tenant of the current
	 * request". OWASP API1 - an id alone must never cross a tenant boundary.
	 */
	private function tenant( ?int $tenant_id ): int {
		return $tenant_id ?? igbz()->tenancy()->id();
	}

	/** @return array<string,mixed>|null Enrollment row guarded by the tenant boundary. */
	private function enrollment_row( int $id, ?int $tenant_id = null ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'enrollments' ) . ' WHERE id = %d AND tenant_id = %d',
			$id,
			$this->tenant( $tenant_id )
		);
	}

	/**
	 * @param array{tenant_id?:int,published?:bool,limit?:int,offset?:int,search?:string} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function courses( array $args = [] ): array {
		$where  = [ '1=1' ];
		$params = [];
		if ( isset( $args['tenant_id'] ) ) {
			$where[]  = 'tenant_id = %d';
			$params[] = (int) $args['tenant_id'];
		}
		if ( ! empty( $args['published'] ) ) {
			$where[] = 'is_published = 1';
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $this->db->wpdb()->esc_like( (string) $args['search'] ) . '%';
		}
		$params[] = (int) ( $args['limit'] ?? 20 );
		$params[] = (int) ( $args['offset'] ?? 0 );

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'courses' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...$params
		);
	}

	/** @param array<string,mixed> $data */
	public function save_course( array $data, int $id = 0 ): int {
		$now = current_time( 'mysql', true );

		// Read once. Written inline as `$data['level'] ?? 'beginner'` the coalesce satisfies the
		// in_array() test when the key is absent, and the cast on the true branch then reads the
		// key that is not there — a notice on every course saved without an explicit level.
		$level = (string) ( $data['level'] ?? 'beginner' );

		$payload = [
			'tenant_id'           => (int) ( $data['tenant_id'] ?? 0 ),
			'product_id'          => (int) ( $data['product_id'] ?? 0 ),
			'title'               => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'slug'                => sanitize_title( (string) ( $data['slug'] ?? $data['title'] ?? 'course' ) ),
			'summary'             => sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ),
			'description'         => wp_kses_post( (string) ( $data['description'] ?? '' ) ),
			'cover_url'           => esc_url_raw( (string) ( $data['cover_url'] ?? '' ) ),
			'level'               => in_array( $level, [ 'beginner', 'intermediate', 'advanced' ], true ) ? $level : 'beginner',
			'duration_minutes'    => (int) ( $data['duration_minutes'] ?? 0 ),
			'instructor_user_id'  => (int) ( $data['instructor_user_id'] ?? get_current_user_id() ),
			'certificate_enabled' => empty( $data['certificate_enabled'] ) ? 0 : 1,
			'pass_score'          => (int) ( $data['pass_score'] ?? 60 ),
			'is_published'        => empty( $data['is_published'] ) ? 0 : 1,
			'updated_at'          => $now,
		];

		if ( $id > 0 ) {
			$this->db->update( 'courses', $payload, [ 'id' => $id ] );
			return $id;
		}
		$payload['created_at'] = $now;
		return $this->db->insert( 'courses', $payload );
	}

	public function delete_course( int $id ): bool {
		$this->db->delete( 'lessons', [ 'course_id' => $id ] );
		$this->db->delete( 'quizzes', [ 'course_id' => $id ] );
		$this->db->delete( 'enrollments', [ 'course_id' => $id ] );
		return $this->db->delete( 'courses', [ 'id' => $id ] ) > 0;
	}

	// -------------------------------------------------------------- lessons

	/** @return array<int,array<string,mixed>> */
	public function lessons( int $course_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'lessons' ) . ' WHERE course_id = %d ORDER BY sort_order, id',
			$course_id
		);
	}

	/** @return array<string,mixed>|null */
	public function lesson( int $id ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'lessons' ) . ' WHERE id = %d AND tenant_id = %d',
			$id,
			$this->tenant( null )
		);
	}

	/** @param array<string,mixed> $data */
	public function save_lesson( array $data, int $id = 0 ): int {
		$payload = [
			'course_id'        => (int) ( $data['course_id'] ?? 0 ),
			'tenant_id'        => (int) ( $data['tenant_id'] ?? 0 ),
			'title'            => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'content'          => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
			'video_key'        => sanitize_text_field( (string) ( $data['video_key'] ?? '' ) ),
			'attachment_url'   => esc_url_raw( (string) ( $data['attachment_url'] ?? '' ) ),
			'duration_minutes' => (int) ( $data['duration_minutes'] ?? 0 ),
			'sort_order'       => (int) ( $data['sort_order'] ?? 0 ),
			'is_free_preview'  => empty( $data['is_free_preview'] ) ? 0 : 1,
		];

		if ( $id > 0 ) {
			$this->db->update( 'lessons', $payload, [ 'id' => $id ] );
			return $id;
		}
		return $this->db->insert( 'lessons', $payload );
	}

	public function delete_lesson( int $id ): bool {
		return $this->db->delete( 'lessons', [ 'id' => $id ] ) > 0;
	}

	// ------------------------------------------------------------ enrollment

	/** @return array<string,mixed>|null */
	public function enrollment( int $course_id, int $user_id, ?int $tenant_id = null ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'enrollments' ) . ' WHERE course_id = %d AND user_id = %d AND tenant_id = %d',
			$course_id,
			$user_id,
			$this->tenant( $tenant_id )
		);
	}

	public function is_enrolled( int $course_id, int $user_id ): bool {
		$enrollment = $this->enrollment( $course_id, $user_id );
		if ( ! $enrollment ) {
			return false;
		}
		if ( ! empty( $enrollment['expires_at'] ) && strtotime( (string) $enrollment['expires_at'] ) < time() ) {
			return false;
		}
		return true;
	}

	/**
	 * Take course access away again.
	 *
	 * The enrollment row is deleted rather than flagged: `enrollments` has UNIQUE (course_id,
	 * user_id) and `enroll()` returns the existing id when it finds one, so a soft-deleted row
	 * would block the customer from ever being enrolled again — including by the same order, if
	 * the refund is itself reversed.
	 *
	 * Progress is deliberately kept. `lesson_progress` is keyed by enrollment id, and a customer
	 * who buys the course again gets a new enrollment, so the old rows are orphaned either way;
	 * deleting them here would only make a support question ("how far had they got?")
	 * unanswerable. They are removed with the course.
	 */
	public function unenroll( int $course_id, int $user_id ): bool {
		$enrollment = $this->enrollment( $course_id, $user_id );
		if ( ! $enrollment ) {
			return false;
		}

		$this->db->delete( 'enrollments', [ 'id' => (int) $enrollment['id'] ] );

		do_action( 'igbz_lms_unenrolled', (int) $enrollment['id'], $course_id, $user_id );

		return true;
	}

	/**
	 * Withdraw the access a refunded or cancelled order paid for.
	 *
	 * Only enrollments this order actually created are touched: `order_id` has to match. A
	 * student who was also enrolled by hand, by a second order or by a subscription keeps that
	 * access, because refunding one order is not a statement about the others.
	 *
	 * @return int Number of enrollments revoked.
	 */
	public function revoke_from_order( int $order_id ): int {
		$rows = $this->db->results(
			'SELECT id, course_id, user_id FROM ' . $this->db->table( 'enrollments' ) . ' WHERE order_id = %d',
			$order_id
		);

		$revoked = 0;
		foreach ( $rows as $row ) {
			$this->db->delete( 'enrollments', [ 'id' => (int) $row['id'] ] );
			do_action( 'igbz_lms_unenrolled', (int) $row['id'], (int) $row['course_id'], (int) $row['user_id'] );
			++$revoked;
		}

		return $revoked;
	}

	public function enroll( int $course_id, int $user_id, int $order_id = 0, ?int $access_days = null ): int {
		$existing = $this->enrollment( $course_id, $user_id );
		if ( $existing ) {
			return (int) $existing['id'];
		}
		$course = $this->course( $course_id );
		if ( ! $course ) {
			return 0;
		}

		$id = $this->db->insert(
			'enrollments',
			[
				'tenant_id'  => (int) $course['tenant_id'],
				'course_id'  => $course_id,
				'user_id'    => $user_id,
				'order_id'   => $order_id,
				'expires_at' => $access_days ? gmdate( 'Y-m-d H:i:s', time() + $access_days * DAY_IN_SECONDS ) : null,
				'created_at' => current_time( 'mysql', true ),
			]
		);

		do_action( 'igbz_lms_enrolled', $id, $course_id, $user_id );
		return $id;
	}

	/** Grant course access for every LMS-linked product in a completed order. */
	public function enroll_from_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id = $item->get_product_id();
			$course     = $this->course_by_product( $product_id );
			if ( $course ) {
				$this->enroll( (int) $course['id'], $user_id, $order_id );
			}
		}
	}

	/** @return array<int,array<string,mixed>> */
	public function enrollments_for_user( int $user_id, int $tenant_id = 0 ): array {
		return $this->db->results(
			'SELECT e.*, c.title, c.slug, c.cover_url
			 FROM ' . $this->db->table( 'enrollments' ) . ' e
			 INNER JOIN ' . $this->db->table( 'courses' ) . ' c ON c.id = e.course_id
			 WHERE e.user_id = %d AND e.tenant_id = %d ORDER BY e.id DESC',
			$user_id,
			$tenant_id
		);
	}

	// -------------------------------------------------------------- progress

	public function record_progress( int $enrollment_id, int $lesson_id, int $seconds_watched, bool $completed = false ): void {
		$enrollment = $this->enrollment_row( $enrollment_id );
		if ( ! $enrollment ) {
			return;
		}

		$this->db->upsert(
			'lesson_progress',
			[
				'enrollment_id'   => $enrollment_id,
				'lesson_id'       => $lesson_id,
				'user_id'         => (int) $enrollment['user_id'],
				'seconds_watched' => $seconds_watched,
				'completed'       => $completed ? 1 : 0,
				'completed_at'    => $completed ? current_time( 'mysql', true ) : null,
				'updated_at'      => current_time( 'mysql', true ),
			],
			[
				// Never let a re-watch shrink the recorded progress, and never un-complete a lesson.
				'seconds_watched' => 'greatest',
				'completed'       => 'greatest',
				'completed_at'    => 'coalesce',
				'updated_at'      => 'value',
			],
			[ 'enrollment_id', 'lesson_id' ]
		);

		$this->refresh_progress( $enrollment_id );
	}

	public function refresh_progress( int $enrollment_id ): int {
		$enrollment = $this->enrollment_row( $enrollment_id );
		if ( ! $enrollment ) {
			return 0;
		}
		$total = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'lessons' ) . ' WHERE course_id = %d',
			(int) $enrollment['course_id']
		);
		if ( 0 === $total ) {
			return 0;
		}
		$done = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'lesson_progress' ) . ' WHERE enrollment_id = %d AND completed = 1',
			$enrollment_id
		);

		$percent = (int) floor( $done / $total * 100 );
		$data    = [ 'progress_percent' => $percent ];

		if ( $percent >= 100 && empty( $enrollment['completed_at'] ) ) {
			$data['completed_at'] = current_time( 'mysql', true );
			do_action( 'igbz_lms_course_completed', $enrollment_id, (int) $enrollment['user_id'], (int) $enrollment['course_id'] );
		}

		$this->db->update( 'enrollments', $data, [ 'id' => $enrollment_id ] );

		// The certificate is decided after the write so it sees the fresh completed_at, and on
		// every refresh rather than only on the transition: a student can finish the lessons
		// before passing the last quiz, and that later pass is what earns the certificate.
		$this->maybe_issue_certificate( $enrollment_id );

		return $percent;
	}

	// --------------------------------------------------------- certificates

	/**
	 * Whether certificates are switched on at all.
	 *
	 * Two switches have to agree. `lms.certificate_enabled` is the site-wide one — an academy
	 * that does not award certificates should not have to remember to untick the box on every
	 * course — and `courses.certificate_enabled` is the per-course one. Before this existed the
	 * site-wide setting was written to the options table, shown on the settings screen and read
	 * by nothing.
	 */
	public function certificates_enabled(): bool {
		return igbz()->settings()->bool( 'lms.certificate_enabled', true );
	}

	/**
	 * Issue the certificate if — and only if — the student has earned it.
	 *
	 * Earning it means three things: every lesson finished, every quiz attached to the course
	 * passed, and both certificate switches on. The quiz clause is the point of the exercise; a
	 * certificate you can get by scrubbing to the end of each video is not worth printing.
	 *
	 * @return string The certificate code, or '' when none is due.
	 */
	public function maybe_issue_certificate( int $enrollment_id ): string {
		$enrollment = $this->enrollment_row( $enrollment_id );
		if ( ! $enrollment ) {
			return '';
		}

		// Already issued: return the same code rather than minting a second one, or the student's
		// certificate would change every time they opened the page.
		if ( ! empty( $enrollment['certificate_code'] ) ) {
			return (string) $enrollment['certificate_code'];
		}

		if ( ! $this->certificates_enabled() ) {
			return '';
		}
		if ( (int) $enrollment['progress_percent'] < 100 ) {
			return '';
		}

		$course_id = (int) $enrollment['course_id'];
		$course    = $this->course( $course_id );
		if ( ! $course || empty( $course['certificate_enabled'] ) ) {
			return '';
		}

		if ( ! $this->has_passed_required_quizzes( $course_id, (int) $enrollment['user_id'] ) ) {
			return '';
		}

		$code = $this->certificate_code( $enrollment_id );
		$this->db->update( 'enrollments', [ 'certificate_code' => $code ], [ 'id' => $enrollment_id ] );

		do_action( 'igbz_lms_certificate_issued', $enrollment_id, $code, (int) $enrollment['user_id'], $course_id );

		return $code;
	}

	/** True when the student has a passing attempt on every quiz of the course (vacuously so when it has none). */
	public function has_passed_required_quizzes( int $course_id, int $user_id ): bool {
		$quizzes = $this->db->column(
			'SELECT id FROM ' . $this->db->table( 'quizzes' ) . ' WHERE course_id = %d',
			$course_id
		);
		if ( ! $quizzes ) {
			return true;
		}

		foreach ( $quizzes as $quiz_id ) {
			$passed = (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'quiz_attempts' ) . ' WHERE quiz_id = %d AND user_id = %d AND passed = 1',
				(int) $quiz_id,
				$user_id
			);
			if ( $passed < 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Look a certificate up by its printed code, for the public verification page.
	 *
	 * Returns only what a verifier needs to see — who, which course, when — and never the
	 * enrollment id, the order or the student's email.
	 *
	 * @return array{code:string,student:string,course:string,completed_at:string,progress:int}|null
	 */
	public function certificate( string $code ): ?array {
		$code = strtoupper( trim( $code ) );
		if ( '' === $code ) {
			return null;
		}

		$row = $this->db->row(
			'SELECT e.certificate_code, e.completed_at, e.progress_percent, e.user_id, c.title
			 FROM ' . $this->db->table( 'enrollments' ) . ' e
			 INNER JOIN ' . $this->db->table( 'courses' ) . ' c ON c.id = e.course_id
			 WHERE e.certificate_code = %s',
			$code
		);
		if ( ! $row ) {
			return null;
		}

		$user = get_userdata( (int) $row['user_id'] );

		return [
			'code'         => (string) $row['certificate_code'],
			'student'      => $user ? (string) $user->display_name : '',
			'course'       => (string) $row['title'],
			'completed_at' => (string) ( $row['completed_at'] ?? '' ),
			'progress'     => (int) $row['progress_percent'],
		];
	}

	private function certificate_code( int $enrollment_id ): string {
		return strtoupper( 'IGBZ-' . substr( hash( 'sha256', $enrollment_id . '|' . microtime( true ) ), 0, 12 ) );
	}

	// ------------------------------------------------------------ protected video

	/**
	 * Signed, expiring video URL. The HMAC secret is generated at install time, never hardcoded.
	 */
	public function signed_video_url( string $video_key, int $user_id, ?int $ttl = null ): string {
		$ttl     = $ttl ?? igbz()->settings()->int( 'lms.video_link_ttl', 7200 );
		$expires = time() + $ttl;
		$secret  = igbz()->settings()->required( 'lms.video_hmac_secret' );
		$payload = $video_key . '|' . $user_id . '|' . $expires;

		return add_query_arg(
			[
				'igbz_video' => rawurlencode( $video_key ),
				'u'          => $user_id,
				'e'          => $expires,
				's'          => Crypto::hmac( $payload, $secret ),
			],
			home_url( '/' )
		);
	}

	public function verify_video_signature( string $video_key, int $user_id, int $expires, string $signature ): bool {
		if ( $expires < time() ) {
			return false;
		}
		$secret = igbz()->settings()->required( 'lms.video_hmac_secret' );
		return Crypto::hmac_equals( Crypto::hmac( $video_key . '|' . $user_id . '|' . $expires, $secret ), $signature );
	}

	// -------------------------------------------------------------- quizzes

	/** @return array<string,mixed>|null */
	public function quiz( int $id ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'quizzes' ) . ' WHERE id = %d AND tenant_id = %d',
			$id,
			$this->tenant( null )
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function quizzes( int $course_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'quizzes' ) . ' WHERE course_id = %d ORDER BY lesson_id, id',
			$course_id
		);
	}

	/**
	 * The effective pass mark for a quiz.
	 *
	 * A quiz row always has a pass_score, but 0 means "never filled in" — the admin form defaults
	 * the box to 60 and nothing stops it being emptied. Falling back through the course to the
	 * site setting means an empty box inherits the academy's standard instead of passing
	 * everybody.
	 *
	 * @param array<string,mixed> $quiz
	 */
	public function pass_score( array $quiz ): int {
		$score = (int) ( $quiz['pass_score'] ?? 0 );
		if ( $score > 0 ) {
			return min( 100, $score );
		}

		$course = $this->course( (int) ( $quiz['course_id'] ?? 0 ) );
		if ( $course && (int) $course['pass_score'] > 0 ) {
			return min( 100, (int) $course['pass_score'] );
		}

		return min( 100, max( 1, igbz()->settings()->int( 'lms.pass_score', 60 ) ) );
	}

	/**
	 * How many times this quiz may be attempted.
	 *
	 * `lms.max_quiz_attempts` is a ceiling, not a default: a course author may be stricter than
	 * the site but not more generous, so nobody can hand themselves unlimited retries on a quiz
	 * by typing a big number into the course form.
	 *
	 * @param array<string,mixed> $quiz
	 * @return int Attempts allowed, or self::ATTEMPTS_UNLIMITED.
	 */
	public function max_attempts( array $quiz ): int {
		$ceiling = igbz()->settings()->int( 'lms.max_quiz_attempts', 3 );
		$quiz_max = (int) ( $quiz['max_attempts'] ?? 0 );

		if ( $ceiling <= 0 ) {
			// The site allows unlimited retries; only the quiz can then impose a limit.
			return $quiz_max > 0 ? $quiz_max : self::ATTEMPTS_UNLIMITED;
		}

		return $quiz_max > 0 ? min( $quiz_max, $ceiling ) : $ceiling;
	}

	public function attempts_used( int $quiz_id, int $user_id ): int {
		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'quiz_attempts' ) . ' WHERE quiz_id = %d AND user_id = %d',
			$quiz_id,
			$user_id
		);
	}

	/** @param array<string,mixed> $quiz */
	public function remaining_attempts( array $quiz, int $user_id ): int {
		$max = $this->max_attempts( $quiz );
		if ( self::ATTEMPTS_UNLIMITED === $max ) {
			return self::ATTEMPTS_UNLIMITED;
		}

		return max( 0, $max - $this->attempts_used( (int) $quiz['id'], $user_id ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function attempts( int $quiz_id, int $user_id, int $limit = 20 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'quiz_attempts' ) . ' WHERE quiz_id = %d AND user_id = %d ORDER BY id DESC LIMIT %d',
			$quiz_id,
			$user_id,
			$limit
		);
	}

	/** @return array<string,mixed>|null The student's best attempt, or null if they have never taken it. */
	public function best_attempt( int $quiz_id, int $user_id ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'quiz_attempts' ) . ' WHERE quiz_id = %d AND user_id = %d ORDER BY passed DESC, score DESC, id ASC LIMIT 1',
			$quiz_id,
			$user_id
		);
	}

	/**
	 * The questions as the browser and the app are allowed to see them.
	 *
	 * The stored JSON carries the answer key. This strips it, and normalises the two shapes the
	 * admin form accepts — `q` or `question` for the text — so a template never has to guess.
	 * Nothing else in the codebase may hand a raw `questions` column to a client.
	 *
	 * @param array<string,mixed> $quiz
	 * @return array<int,array{id:string,question:string,options:array<int,string>,multiple:bool}>
	 */
	public function questions_for_client( array $quiz ): array {
		$decoded = json_decode( (string) ( $quiz['questions'] ?? '' ), true );
		$decoded = is_array( $decoded ) ? $decoded : [];

		$out = [];
		foreach ( $decoded as $index => $question ) {
			if ( ! is_array( $question ) ) {
				continue;
			}

			$options = [];
			foreach ( (array) ( $question['options'] ?? [] ) as $option ) {
				$options[] = (string) ( is_scalar( $option ) ? $option : '' );
			}

			$out[] = [
				'id'       => (string) ( $question['id'] ?? $index ),
				'question' => (string) ( $question['q'] ?? ( $question['question'] ?? '' ) ),
				'options'  => $options,
				// A question whose answer is a list needs checkboxes, not radios.
				'multiple' => is_array( $question['answer'] ?? null ),
			];
		}

		return $out;
	}

	/**
	 * Everything a learner surface needs to draw one quiz: the questions without their answers,
	 * the rules, and where this student stands.
	 *
	 * @param array<string,mixed> $quiz
	 * @return array<string,mixed>
	 */
	public function quiz_for_user( array $quiz, int $user_id ): array {
		$best      = $user_id > 0 ? $this->best_attempt( (int) $quiz['id'], $user_id ) : null;
		$remaining = $user_id > 0 ? $this->remaining_attempts( $quiz, $user_id ) : $this->max_attempts( $quiz );

		return [
			'id'           => (int) $quiz['id'],
			'course_id'    => (int) $quiz['course_id'],
			'lesson_id'    => (int) $quiz['lesson_id'],
			'title'        => (string) $quiz['title'],
			'pass_score'   => $this->pass_score( $quiz ),
			'max_attempts' => $this->max_attempts( $quiz ),
			'time_limit'   => (int) $quiz['time_limit_minutes'],
			'questions'    => $this->questions_for_client( $quiz ),
			'attempts_used' => $user_id > 0 ? $this->attempts_used( (int) $quiz['id'], $user_id ) : 0,
			'remaining_attempts' => $remaining,
			'best_score'   => $best ? (int) $best['score'] : null,
			'passed'       => $best ? (bool) $best['passed'] : false,
			'can_attempt'  => self::ATTEMPTS_UNLIMITED === $remaining || $remaining > 0,
		];
	}

	/** @param array<string,mixed> $data */
	public function save_quiz( array $data, int $id = 0 ): int {
		$payload = [
			'course_id'          => (int) ( $data['course_id'] ?? 0 ),
			'lesson_id'          => (int) ( $data['lesson_id'] ?? 0 ),
			'tenant_id'          => (int) ( $data['tenant_id'] ?? 0 ),
			'title'              => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'questions'          => wp_json_encode( (array) ( $data['questions'] ?? [] ) ),
			'pass_score'         => (int) ( $data['pass_score'] ?? 60 ),
			'max_attempts'       => (int) ( $data['max_attempts'] ?? 3 ),
			'time_limit_minutes' => (int) ( $data['time_limit_minutes'] ?? 0 ),
		];
		if ( $id > 0 ) {
			$this->db->update( 'quizzes', $payload, [ 'id' => $id ] );
			return $id;
		}
		$payload['created_at'] = current_time( 'mysql', true );
		return $this->db->insert( 'quizzes', $payload );
	}

	/**
	 * Grade a quiz submission server-side. Correct answers are never exposed to the client.
	 *
	 * @param array<int|string,mixed> $answers
	 * @return array{score:int,passed:bool,attempt_id:int,remaining_attempts:int,certificate_code:string}
	 */
	public function submit_quiz( int $quiz_id, int $user_id, array $answers ): array {
		$quiz = $this->quiz( $quiz_id );
		if ( ! $quiz ) {
			throw new \RuntimeException( __( 'Quiz not found.', 'igbz-suite' ) );
		}

		// Enrollment is checked here rather than only in the callers. Every surface that grades a
		// quiz — the shortcode, the REST route, whatever comes next — has to apply the same rule,
		// and a rule enforced in three places is a rule enforced in two.
		if ( ! $this->is_enrolled( (int) $quiz['course_id'], $user_id ) ) {
			throw new \RuntimeException( __( 'You need access to this course before taking its quizzes.', 'igbz-suite' ) );
		}

		$used = $this->attempts_used( $quiz_id, $user_id );
		$max  = $this->max_attempts( $quiz );
		if ( self::ATTEMPTS_UNLIMITED !== $max && $used >= $max ) {
			throw new \RuntimeException( __( 'You have used all your attempts for this quiz.', 'igbz-suite' ) );
		}

		$questions = json_decode( (string) $quiz['questions'], true );
		$questions = is_array( $questions ) ? $questions : [];
		$total     = count( $questions );
		$correct   = 0;

		foreach ( $questions as $index => $question ) {
			$key      = (string) ( $question['id'] ?? $index );
			$given    = $answers[ $key ] ?? ( $answers[ $index ] ?? null );
			$expected = $question['answer'] ?? null;
			if ( is_array( $expected ) ) {
				$given_set    = array_map( 'strval', (array) $given );
				$expected_set = array_map( 'strval', $expected );
				sort( $given_set );
				sort( $expected_set );
				if ( $given_set === $expected_set ) {
					$correct++;
				}
			} elseif ( null !== $given && (string) $given === (string) $expected ) {
				$correct++;
			}
		}

		$score  = $total > 0 ? (int) round( $correct / $total * 100 ) : 0;
		$passed = $score >= $this->pass_score( $quiz );

		$attempt_id = $this->db->insert(
			'quiz_attempts',
			[
				'quiz_id'     => $quiz_id,
				'user_id'     => $user_id,
				'tenant_id'   => (int) $quiz['tenant_id'],
				'answers'     => wp_json_encode( $answers ),
				'score'       => $score,
				'passed'      => $passed ? 1 : 0,
				'started_at'  => current_time( 'mysql', true ),
				'finished_at' => current_time( 'mysql', true ),
			]
		);

		do_action( 'igbz_lms_quiz_submitted', $attempt_id, $quiz_id, $user_id, $score, $passed );

		// Passing the last outstanding quiz can be the thing that completes the course, so the
		// certificate has to be reconsidered here and not only when a lesson is ticked off.
		$certificate = '';
		if ( $passed ) {
			$enrollment = $this->enrollment( (int) $quiz['course_id'], $user_id );
			if ( $enrollment ) {
				$certificate = $this->maybe_issue_certificate( (int) $enrollment['id'] );
			}
		}

		return [
			'score'              => $score,
			'passed'             => $passed,
			'attempt_id'         => $attempt_id,
			'remaining_attempts' => self::ATTEMPTS_UNLIMITED === $max ? self::ATTEMPTS_UNLIMITED : max( 0, $max - $used - 1 ),
			'certificate_code'   => $certificate,
		];
	}
}
