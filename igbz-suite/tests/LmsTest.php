<?php
/**
 * The LMS: who may take a quiz, what a pass is worth, and what a refund takes back.
 *
 * Three things were wired up here that had been sitting half-finished, and each of them is the
 * kind of bug that looks like nothing on the screen:
 *
 *   - submit_quiz() had no caller at all. Quizzes could be authored and stored, and no learner
 *     could ever reach one. The grading code was correct and dead.
 *   - lms.certificate_enabled was written by the settings screen and read by nobody, and a
 *     certificate was minted as soon as the last video was ticked — a student could scrub to the
 *     end of every lesson, fail every quiz, and print the certificate.
 *   - a refunded order left the enrollment behind, so "buy, watch, refund, keep" was free.
 *
 * The tests below pin the rules those three fixes now depend on: an attempt ceiling that the
 * site can tighten but a course cannot loosen, a pass mark that falls back rather than passing
 * everybody, a certificate that requires the quizzes as well as the lessons, and a revocation
 * that takes back exactly what one order paid for and nothing else.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Support\Db;

/**
 * An in-memory stand-in for the six LMS tables.
 *
 * Same reasoning as VipDb: the services write a row and then read it back through another method
 * — grade a quiz, then ask whether the certificate is due — so a queue of canned rows would agree
 * with the code whatever the code did. This keeps real rows and answers the predicates the
 * service actually issues, reading values out of the prepared SQL rather than restating the
 * WHERE clause in PHP.
 */
final class LmsDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> table short name => id => row */
	public array $tables = [
		'courses'         => [],
		'lessons'         => [],
		'enrollments'     => [],
		'lesson_progress' => [],
		'quizzes'         => [],
		'quiz_attempts'   => [],
	];

	private int $next_id = 1;

	// ------------------------------------------------------------- seeding

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                            = (int) ( $row['id'] ?? $this->next_id++ );
		$row['id']                     = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	/** @param array<string,mixed> $row */
	public function seed_course( array $row = [] ): int {
		$now = gmdate( 'Y-m-d H:i:s' );

		return $this->seed(
			'courses',
			array_merge(
				[
					'tenant_id'           => 1,
					'product_id'          => 0,
					'title'               => 'Baking sourdough',
					'slug'                => 'baking-sourdough',
					'summary'             => '',
					'description'         => '',
					'cover_url'           => '',
					'level'               => 'beginner',
					'duration_minutes'    => 0,
					'instructor_user_id'  => 99,
					'certificate_enabled' => 1,
					'pass_score'          => 60,
					'is_published'        => 1,
					'created_at'          => $now,
					'updated_at'          => $now,
				],
				$row
			)
		);
	}

	/** @param array<string,mixed> $row */
	public function seed_lesson( array $row = [] ): int {
		return $this->seed(
			'lessons',
			array_merge(
				[
					'course_id'        => 1,
					'tenant_id'        => 1,
					'title'            => 'Feeding the starter',
					'content'          => '',
					'video_key'        => 'lesson-1.mp4',
					'attachment_url'   => '',
					'duration_minutes' => 12,
					'sort_order'       => 0,
					'is_free_preview'  => 0,
				],
				$row
			)
		);
	}

	/** @param array<string,mixed> $row */
	public function seed_enrollment( array $row = [] ): int {
		return $this->seed(
			'enrollments',
			array_merge(
				[
					'tenant_id'        => 1,
					'course_id'        => 1,
					'user_id'          => 7,
					'order_id'         => 0,
					'progress_percent' => 0,
					'completed_at'     => null,
					'certificate_code' => '',
					'expires_at'       => null,
					'created_at'       => gmdate( 'Y-m-d H:i:s' ),
				],
				$row
			)
		);
	}

	/** @param array<string,mixed> $row */
	public function seed_quiz( array $row = [] ): int {
		$questions = $row['questions'] ?? [
			[ 'id' => 'q1', 'q' => 'Hydration of a 1:1 starter?', 'options' => [ '50%', '100%', '150%' ], 'answer' => 1 ],
			[ 'id' => 'q2', 'q' => 'Which flours can feed it?', 'options' => [ 'Rye', 'Plain', 'Sand' ], 'answer' => [ 0, 1 ] ],
		];
		unset( $row['questions'] );

		return $this->seed(
			'quizzes',
			array_merge(
				[
					'course_id'          => 1,
					'lesson_id'          => 0,
					'tenant_id'          => 1,
					'title'              => 'Starter basics',
					'questions'          => wp_json_encode( $questions ),
					'pass_score'         => 60,
					'max_attempts'       => 3,
					'time_limit_minutes' => 0,
					'created_at'         => gmdate( 'Y-m-d H:i:s' ),
				],
				$row
			)
		);
	}

	/** @param array<string,mixed> $row */
	public function seed_attempt( array $row = [] ): int {
		$now = gmdate( 'Y-m-d H:i:s' );

		return $this->seed(
			'quiz_attempts',
			array_merge(
				[
					'quiz_id'     => 1,
					'user_id'     => 7,
					'tenant_id'   => 1,
					'answers'     => '{}',
					'score'       => 0,
					'passed'      => 0,
					'started_at'  => $now,
					'finished_at' => $now,
				],
				$row
			)
		);
	}

	/** @return array<string,mixed> */
	public function get( string $table, int $id ): array {
		return $this->tables[ $table ][ $id ] ?? [];
	}

	/** @return array<int,array<string,mixed>> */
	public function all( string $table ): array {
		return array_values( $this->tables[ $table ] );
	}

	// ------------------------------------------------------------- parsing

	private static function which( string $sql ): string {
		// Longest first: lesson_progress also contains lessons' prefix in some statements.
		$names = [ 'lesson_progress', 'quiz_attempts', 'enrollments', 'quizzes', 'courses', 'lessons', 'tenants' ];

		foreach ( $names as $name ) {
			if ( str_contains( $sql, 'igbz_' . $name ) ) {
				return $name;
			}
		}

		return '';
	}

	private static function value_of( string $column, string $sql ): ?string {
		return preg_match( '/\b' . preg_quote( $column, '/' ) . " = '([^']*)'/", $sql, $m ) ? $m[1] : null;
	}

	private static function int_of( string $column, string $sql ): int {
		return (int) self::value_of( $column, $sql );
	}

	/**
	 * Rows of $table matching every `column = 'value'` pair named in $columns.
	 *
	 * @param array<int,string> $columns
	 * @return array<int,array<string,mixed>>
	 */
	private function matching( string $table, string $sql, array $columns ): array {
		$out = [];

		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			foreach ( $columns as $column ) {
				$wanted = self::value_of( $column, $sql );
				if ( null !== $wanted && (string) ( $row[ $column ] ?? '' ) !== $wanted ) {
					continue 2;
				}
			}
			$out[] = $row;
		}

		return $out;
	}

	// --------------------------------------------------------------- reads

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( '' === $table ) {
			return parent::get_row( $sql, $output );
		}

		// certificate() joins enrollments to courses; the join is answered by hand because the
		// double parses one table per statement.
		if ( str_contains( $sql, 'certificate_code = ' ) ) {
			$code = self::value_of( 'e.certificate_code', $sql ) ?? self::value_of( 'certificate_code', $sql );
			foreach ( $this->tables['enrollments'] as $row ) {
				if ( (string) $row['certificate_code'] !== (string) $code ) {
					continue;
				}
				$course = $this->tables['courses'][ (int) $row['course_id'] ] ?? [];
				return array_merge( $row, [ 'title' => (string) ( $course['title'] ?? '' ) ] );
			}
			return null;
		}

		if ( 'enrollments' === $table && str_contains( $sql, 'course_id = ' ) && str_contains( $sql, 'user_id = ' ) ) {
			$rows = $this->matching( $table, $sql, [ 'course_id', 'user_id', 'tenant_id' ] );
			return $rows[0] ?? null;
		}

		if ( 'quiz_attempts' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'quiz_id', 'user_id' ] );
			// best_attempt(): ORDER BY passed DESC, score DESC, id ASC LIMIT 1.
			usort(
				$rows,
				static function ( array $a, array $b ): int {
					return [ (int) $b['passed'], (int) $b['score'], -(int) $a['id'] ]
						<=> [ (int) $a['passed'], (int) $a['score'], -(int) $b['id'] ];
				}
			);
			return $rows[0] ?? null;
		}

		if ( 'courses' === $table && str_contains( $sql, 'slug = ' ) ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( (string) $row['slug'] === self::value_of( 'slug', $sql ) ) {
					return $row;
				}
			}
			return null;
		}

		if ( 'courses' === $table && str_contains( $sql, 'product_id = ' ) ) {
			$rows = $this->matching( $table, $sql, [ 'product_id', 'tenant_id' ] );
			return $rows[0] ?? null;
		}

		// Phase 07: id lookups carry the tenant boundary too; honour it when present.
		$id = self::int_of( 'id', $sql );
		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			if ( (int) $row['id'] !== $id ) {
				continue;
			}
			$tenant = self::value_of( 'tenant_id', $sql );
			if ( null !== $tenant && (string) ( $row['tenant_id'] ?? '' ) !== $tenant ) {
				return null;
			}
			return $row;
		}
		return null;
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( '' === $table ) {
			return parent::get_results( $sql, $output );
		}

		if ( 'lessons' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'course_id' ] );
			usort( $rows, static fn ( array $a, array $b ): int => [ (int) $a['sort_order'], (int) $a['id'] ] <=> [ (int) $b['sort_order'], (int) $b['id'] ] );
			return $rows;
		}

		if ( 'quizzes' === $table ) {
			return $this->matching( $table, $sql, [ 'course_id' ] );
		}

		if ( 'quiz_attempts' === $table ) {
			return $this->matching( $table, $sql, [ 'quiz_id', 'user_id' ] );
		}

		if ( 'enrollments' === $table ) {
			// enrollments_for_user() joins courses; revoke_from_order() does not.
			if ( str_contains( $sql, 'order_id = ' ) ) {
				return $this->matching( $table, $sql, [ 'order_id' ] );
			}

			$rows = $this->matching( $table, $sql, [ 'e.user_id', 'user_id', 'certificate_code' ] );
			$out  = [];
			foreach ( $rows as $row ) {
				$course = $this->tables['courses'][ (int) $row['course_id'] ] ?? [];
				$out[]  = array_merge(
					$row,
					[
						'title'     => (string) ( $course['title'] ?? '' ),
						'slug'      => (string) ( $course['slug'] ?? '' ),
						'cover_url' => (string) ( $course['cover_url'] ?? '' ),
					]
				);
			}
			return $out;
		}

		return array_values( $this->tables[ $table ] );
	}

	public function get_col( string $sql ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( 'quizzes' === $table ) {
			$out = [];
			foreach ( $this->matching( $table, $sql, [ 'course_id' ] ) as $row ) {
				$out[] = (int) $row['id'];
			}
			return $out;
		}

		return parent::get_col( $sql );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( '' === $table ) {
			return parent::get_var( $sql );
		}

		if ( ! str_contains( $sql, 'COUNT(' ) && ! str_contains( $sql, 'AVG(' ) ) {
			return parent::get_var( $sql );
		}

		if ( 'quiz_attempts' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'quiz_id', 'user_id' ] );
			if ( str_contains( $sql, 'passed = 1' ) ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => 1 === (int) $row['passed'] );
			}
			return count( $rows );
		}

		if ( 'lesson_progress' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'enrollment_id' ] );
			if ( str_contains( $sql, 'completed = 1' ) ) {
				$rows = array_filter( $rows, static fn ( array $row ): bool => 1 === (int) $row['completed'] );
			}
			return count( $rows );
		}

		return count( $this->matching( $table, $sql, [ 'course_id', 'user_id', 'enrollment_id', 'quiz_id' ] ) );
	}

	// -------------------------------------------------------------- writes

	public function insert( string $table, array $data, $format = null ): int|bool {
		$short = self::which( 'igbz_' . str_replace( $this->prefix . 'igbz_', '', $table ) );
		if ( '' === $short ) {
			return parent::insert( $table, $data, $format );
		}

		// UNIQUE (course_id, user_id) on enrollments.
		if ( 'enrollments' === $short ) {
			foreach ( $this->tables[ $short ] as $row ) {
				if ( (int) $row['course_id'] === (int) $data['course_id'] && (int) $row['user_id'] === (int) $data['user_id'] ) {
					return false;
				}
			}
		}

		$id                            = $this->next_id++;
		$this->insert_id               = $id;
		$data['id']                    = $id;
		$this->tables[ $short ][ $id ] = $data;

		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$short = self::which( 'igbz_' . str_replace( $this->prefix . 'igbz_', '', $table ) );
		if ( '' === $short ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}

		$changed = 0;
		foreach ( $this->tables[ $short ] as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			$this->tables[ $short ][ $id ] = array_merge( $row, $data );
			++$changed;
		}

		return $changed;
	}

	public function delete( string $table, array $where, $where_format = null ): int|bool {
		$short = self::which( 'igbz_' . str_replace( $this->prefix . 'igbz_', '', $table ) );
		if ( '' === $short ) {
			return parent::delete( $table, $where, $where_format );
		}

		$removed = 0;
		foreach ( $this->tables[ $short ] as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			unset( $this->tables[ $short ][ $id ] );
			++$removed;
		}

		return $removed;
	}

	/** The upsert path used by record_progress(). */
	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		if ( ! str_contains( $sql, 'igbz_lesson_progress' ) || ! str_contains( $sql, 'INSERT' ) ) {
			return parent::query( $sql );
		}

		$enrollment = self::int_of( 'enrollment_id', $sql );
		$lesson     = self::int_of( 'lesson_id', $sql );

		// The upsert writes a VALUES list, not `column = 'value'` pairs, so the ids come out of
		// the tail of the statement instead.
		if ( 0 === $enrollment && preg_match( "/VALUES\s*\(\s*'(\d+)',\s*'(\d+)',\s*'(\d+)',\s*'(\d+)',\s*'(\d+)'/", $sql, $m ) ) {
			$enrollment = (int) $m[1];
			$lesson     = (int) $m[2];
			$completed  = (int) $m[5];
		} else {
			$completed = str_contains( $sql, "'1'" ) ? 1 : 0;
		}

		foreach ( $this->tables['lesson_progress'] as $id => $row ) {
			if ( (int) $row['enrollment_id'] === $enrollment && (int) $row['lesson_id'] === $lesson ) {
				// GREATEST semantics: progress never goes backwards.
				$this->tables['lesson_progress'][ $id ]['completed'] = max( (int) $row['completed'], $completed );
				return 1;
			}
		}

		$this->seed(
			'lesson_progress',
			[
				'enrollment_id' => $enrollment,
				'lesson_id'     => $lesson,
				'user_id'       => 0,
				'completed'     => $completed,
			]
		);

		return 1;
	}
}

final class LmsTest extends TestCase {

	private LmsDb $db;

	private LmsService $lms;

	private function boot(): void {
		$settings = igbz_test_reset_settings();

		$this->db        = new LmsDb();
		$GLOBALS['wpdb'] = $this->db;

		// Phase 07 seeds live in tenant 1; object reads now enforce the boundary, so the test
		// request has to sit inside the same tenant.
		igbz()->tenancy()->force( 1 );
		$this->db->seed( 'tenants', [ 'id' => 1, 'slug' => 'test', 'name' => 'Test', 'owner_user_id' => 1, 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa' ] );

		// Every LMS setting the service reads, pinned so a changed default cannot move a test.
		$settings->set_many(
			[
				'lms.enabled'             => true,
				'lms.video_hmac_secret'   => 'test-lms-secret',
				'lms.video_link_ttl'      => 7200,
				'lms.max_quiz_attempts'   => 3,
				'lms.pass_score'          => 60,
				'lms.certificate_enabled' => true,
				'lms.certificate_slug'    => 'certificate',
				'lms.revoke_on_refund'    => true,
			]
		);

		$this->lms = new LmsService( new Db() );
		igbz()->bind( 'lms', fn () => $this->lms );
	}

	public function run(): void {
		$this->test_a_quiz_reaches_the_learner_without_its_answers();
		$this->test_grading_handles_single_and_multiple_answers();
		$this->test_a_stranger_cannot_submit_a_quiz();
		$this->test_the_site_ceiling_beats_a_generous_quiz();
		$this->test_a_course_may_be_stricter_than_the_site();
		$this->test_attempts_run_out();
		$this->test_an_empty_pass_score_falls_back_instead_of_passing_everybody();
		$this->test_finishing_the_lessons_is_not_enough_for_a_certificate();
		$this->test_passing_the_last_quiz_issues_the_certificate();
		$this->test_a_course_without_quizzes_certifies_on_the_lessons_alone();
		$this->test_the_site_switch_can_withhold_every_certificate();
		$this->test_a_certificate_is_never_minted_twice();
		$this->test_a_certificate_can_be_verified_by_its_code();
		$this->test_saving_a_course_without_a_level_does_not_warn();
		$this->test_a_refund_takes_the_course_back();
		$this->test_a_refund_leaves_other_enrollments_alone();
		$this->test_revoked_access_can_be_granted_again();
	}

	// ----------------------------------------------------------- delivery

	/**
	 * The reason submit_quiz() was unreachable is that nothing ever turned a quiz row into
	 * something a browser could render. questions_for_client() is that step, and its one hard
	 * rule is that the answer key does not travel with it.
	 */
	private function test_a_quiz_reaches_the_learner_without_its_answers(): void {
		$this->boot();
		$this->db->seed_course();
		$quiz_id = $this->db->seed_quiz();

		$payload = $this->lms->quiz_for_user( $this->db->get( 'quizzes', $quiz_id ), 7 );
		$json    = (string) wp_json_encode( $payload );

		$this->assert_same( 2, count( $payload['questions'] ), 'both questions are handed to the client' );
		$this->assert_same( 'Hydration of a 1:1 starter?', $payload['questions'][0]['question'], 'the question text survives the "q" key' );
		$this->assert_same( 3, count( $payload['questions'][0]['options'] ), 'every option is offered' );
		$this->assert_false( str_contains( $json, 'answer' ), 'the answer key never leaves the server' );
		$this->assert_false( $payload['questions'][0]['multiple'], 'a single-answer question asks for one choice' );
		$this->assert_true( $payload['questions'][1]['multiple'], 'a list answer marks the question as multiple choice' );
	}

	/** Grading has to cope with both shapes the admin form accepts. */
	private function test_grading_handles_single_and_multiple_answers(): void {
		$this->boot();
		$this->db->seed_course();
		$quiz_id = $this->db->seed_quiz();
		$this->db->seed_enrollment();

		// Both right: 1 for the single, [0,1] in either order for the list.
		$result = $this->lms->submit_quiz( $quiz_id, 7, [ 'q1' => '1', 'q2' => [ '1', '0' ] ] );
		$this->assert_same( 100, $result['score'], 'order does not matter for a multiple-answer question' );
		$this->assert_true( $result['passed'], 'a perfect sheet passes' );

		// One right, one wrong.
		$this->boot();
		$this->db->seed_course();
		$quiz_id = $this->db->seed_quiz();
		$this->db->seed_enrollment();

		$result = $this->lms->submit_quiz( $quiz_id, 7, [ 'q1' => '1', 'q2' => [ '0' ] ] );
		$this->assert_same( 50, $result['score'], 'a partial multiple-answer is simply wrong, not half right' );
		$this->assert_false( $result['passed'], '50% is under the 60% pass mark' );
	}

	/**
	 * Enrollment is checked inside the service, not only by the callers.
	 *
	 * The REST route and the shortcode both gate on it, but a third surface added later would
	 * have to remember to, and the answer sheet is a public POST.
	 */
	private function test_a_stranger_cannot_submit_a_quiz(): void {
		$this->boot();
		$this->db->seed_course();
		$quiz_id = $this->db->seed_quiz();
		// No enrollment seeded.

		$refused = false;
		try {
			$this->lms->submit_quiz( $quiz_id, 7, [ 'q1' => '1' ] );
		} catch ( \RuntimeException $e ) {
			$refused = true;
		}

		$this->assert_true( $refused, 'a quiz submission from somebody who never bought the course is rejected' );
		$this->assert_same( 0, count( $this->db->all( 'quiz_attempts' ) ), 'and no attempt is recorded' );
	}

	// ------------------------------------------------------------ attempts

	/**
	 * lms.max_quiz_attempts is a ceiling, not a default.
	 *
	 * If it were a default, a course author could type 99 into the quiz form and hand themselves
	 * unlimited retries on a site that had deliberately allowed three.
	 */
	private function test_the_site_ceiling_beats_a_generous_quiz(): void {
		$this->boot();
		$this->db->seed_course();
		$quiz_id = $this->db->seed_quiz( [ 'max_attempts' => 99 ] );

		$this->assert_same( 3, $this->lms->max_attempts( $this->db->get( 'quizzes', $quiz_id ) ), 'the site ceiling wins over a larger quiz limit' );
	}

	/** The other direction: a quiz may be stricter than the site allows in general. */
	private function test_a_course_may_be_stricter_than_the_site(): void {
		$this->boot();
		$this->db->seed_course();
		$quiz_id = $this->db->seed_quiz( [ 'max_attempts' => 1 ] );

		$this->assert_same( 1, $this->lms->max_attempts( $this->db->get( 'quizzes', $quiz_id ) ), 'a one-shot quiz stays a one-shot quiz' );
	}

	private function test_attempts_run_out(): void {
		$this->boot();
		$this->db->seed_course();
		$quiz_id = $this->db->seed_quiz( [ 'max_attempts' => 2 ] );
		$this->db->seed_enrollment();

		$first = $this->lms->submit_quiz( $quiz_id, 7, [ 'q1' => '0' ] );
		$this->assert_same( 1, $first['remaining_attempts'], 'the first failure leaves one attempt' );

		$second = $this->lms->submit_quiz( $quiz_id, 7, [ 'q1' => '0' ] );
		$this->assert_same( 0, $second['remaining_attempts'], 'the second exhausts them' );

		$refused = false;
		try {
			$this->lms->submit_quiz( $quiz_id, 7, [ 'q1' => '1' ] );
		} catch ( \RuntimeException $e ) {
			$refused = true;
		}

		$this->assert_true( $refused, 'a third submission is refused even though the answers are right' );
		$this->assert_same( 2, count( $this->db->all( 'quiz_attempts' ) ), 'and it is not recorded' );
	}

	/**
	 * A quiz saved with an empty pass score used to store 0, and `$score >= 0` is true for a
	 * blank answer sheet — everybody passed. It now falls back to the course, then the site.
	 */
	private function test_an_empty_pass_score_falls_back_instead_of_passing_everybody(): void {
		$this->boot();
		$this->db->seed_course( [ 'pass_score' => 80 ] );
		$quiz_id = $this->db->seed_quiz( [ 'pass_score' => 0 ] );
		$this->db->seed_enrollment();

		$this->assert_same( 80, $this->lms->pass_score( $this->db->get( 'quizzes', $quiz_id ) ), 'an empty quiz score inherits the course pass mark' );

		$result = $this->lms->submit_quiz( $quiz_id, 7, [] );
		$this->assert_same( 0, $result['score'], 'an empty sheet scores nothing' );
		$this->assert_false( $result['passed'], 'and nothing is not a pass' );
	}

	// -------------------------------------------------------- certificates

	/**
	 * The headline fix. Ticking off every lesson is no longer enough on a course that has a
	 * quiz — before this, the certificate was minted the moment progress hit 100%.
	 */
	private function test_finishing_the_lessons_is_not_enough_for_a_certificate(): void {
		$this->boot();
		$this->db->seed_course();
		$lesson_id     = $this->db->seed_lesson();
		$this->db->seed_quiz();
		$enrollment_id = $this->db->seed_enrollment();

		$this->lms->record_progress( $enrollment_id, $lesson_id, 700, true );

		$enrollment = $this->db->get( 'enrollments', $enrollment_id );
		$this->assert_same( 100, (int) $enrollment['progress_percent'], 'the only lesson is done, so the course reads 100%' );
		$this->assert_same( '', (string) $enrollment['certificate_code'], 'but the unpassed quiz withholds the certificate' );
	}

	/** ...and passing it later hands the certificate over, without another lesson being touched. */
	private function test_passing_the_last_quiz_issues_the_certificate(): void {
		$this->boot();
		$this->db->seed_course();
		$lesson_id     = $this->db->seed_lesson();
		$quiz_id       = $this->db->seed_quiz();
		$enrollment_id = $this->db->seed_enrollment();

		$this->lms->record_progress( $enrollment_id, $lesson_id, 700, true );
		$result = $this->lms->submit_quiz( $quiz_id, 7, [ 'q1' => '1', 'q2' => [ '0', '1' ] ] );

		$this->assert_true( $result['passed'], 'the answers are right' );
		$this->assert_true( '' !== $result['certificate_code'], 'submitting the last quiz is what earns the certificate' );

		$enrollment = $this->db->get( 'enrollments', $enrollment_id );
		$this->assert_contains( 'IGBZ-', (string) $enrollment['certificate_code'], 'and it is stored on the enrollment' );
	}

	private function test_a_course_without_quizzes_certifies_on_the_lessons_alone(): void {
		$this->boot();
		$this->db->seed_course();
		$lesson_id     = $this->db->seed_lesson();
		$enrollment_id = $this->db->seed_enrollment();

		$this->lms->record_progress( $enrollment_id, $lesson_id, 700, true );

		$this->assert_contains(
			'IGBZ-',
			(string) $this->db->get( 'enrollments', $enrollment_id )['certificate_code'],
			'a course with no assessment still certifies when the lessons are finished'
		);
	}

	/**
	 * lms.certificate_enabled was the setting that did nothing. Turning it off has to stop every
	 * certificate on the site, whatever the individual courses say.
	 */
	private function test_the_site_switch_can_withhold_every_certificate(): void {
		$this->boot();
		igbz()->settings()->set( 'lms.certificate_enabled', false );

		$this->db->seed_course( [ 'certificate_enabled' => 1 ] );
		$lesson_id     = $this->db->seed_lesson();
		$enrollment_id = $this->db->seed_enrollment();

		$this->lms->record_progress( $enrollment_id, $lesson_id, 700, true );

		$this->assert_same(
			'',
			(string) $this->db->get( 'enrollments', $enrollment_id )['certificate_code'],
			'the site-wide switch overrides a course that asks for certificates'
		);
	}

	/** Re-opening the course must not change the code the student already has. */
	private function test_a_certificate_is_never_minted_twice(): void {
		$this->boot();
		$this->db->seed_course();
		$lesson_id     = $this->db->seed_lesson();
		$enrollment_id = $this->db->seed_enrollment();

		$this->lms->record_progress( $enrollment_id, $lesson_id, 700, true );
		$first = (string) $this->db->get( 'enrollments', $enrollment_id )['certificate_code'];

		$again = $this->lms->maybe_issue_certificate( $enrollment_id );

		$this->assert_same( $first, $again, 'the same code comes back on every later visit' );
	}

	private function test_a_certificate_can_be_verified_by_its_code(): void {
		$this->boot();
		$this->db->seed_course( [ 'title' => 'Baking sourdough' ] );
		$lesson_id     = $this->db->seed_lesson();
		$enrollment_id = $this->db->seed_enrollment( [ 'user_id' => 7 ] );

		$this->lms->record_progress( $enrollment_id, $lesson_id, 700, true );
		$code = (string) $this->db->get( 'enrollments', $enrollment_id )['certificate_code'];

		$found = $this->lms->certificate( $code );
		$this->assert_true( null !== $found, 'the printed code resolves' );
		$this->assert_same( 'Baking sourdough', (string) $found['course'], 'and names the course' );
		$this->assert_same( 'User 7', (string) $found['student'], 'and the holder' );

		$this->assert_true( null === $this->lms->certificate( 'IGBZ-NOTREAL0000' ), 'an invented code resolves to nothing' );
		$this->assert_true( null === $this->lms->certificate( '' ), 'and so does an empty one' );
	}

	/**
	 * Found by saving a course from a script that omitted `level`.
	 *
	 * `in_array( $data['level'] ?? 'beginner', ... ) ? (string) $data['level'] : 'beginner'` reads
	 * the key twice: the coalesce satisfies the test, and the cast on the true branch then reads
	 * a key that is not there. Every such save emitted a PHP warning, and on a site with
	 * display_errors on that is a warning printed into the admin page.
	 */
	private function test_saving_a_course_without_a_level_does_not_warn(): void {
		$this->boot();

		$warnings = [];
		set_error_handler(
			static function ( int $errno, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;
				return true;
			},
			E_WARNING | E_NOTICE
		);

		$id = $this->lms->save_course( [ 'title' => 'No level given', 'slug' => 'no-level' ] );

		restore_error_handler();

		$this->assert_same( [], $warnings, 'saving a course without a level emits no warning' );
		$this->assert_same( 'beginner', (string) $this->db->get( 'courses', $id )['level'], 'and falls back to beginner' );
	}

	// ------------------------------------------------------------- refunds

	/**
	 * Buy, watch, refund, keep. The enrollment outlived the order that paid for it, and nothing
	 * ever looked at it again.
	 */
	private function test_a_refund_takes_the_course_back(): void {
		$this->boot();
		$this->db->seed_course();
		$this->db->seed_enrollment( [ 'order_id' => 500, 'user_id' => 7 ] );

		$this->assert_true( $this->lms->is_enrolled( 1, 7 ), 'the purchase granted access' );

		$revoked = $this->lms->revoke_from_order( 500 );

		$this->assert_same( 1, $revoked, 'the refund revokes the enrollment it paid for' );
		$this->assert_false( $this->lms->is_enrolled( 1, 7 ), 'and the course closes again' );
	}

	/**
	 * Refunding one order says nothing about the others. A student enrolled by hand, or on a
	 * second purchase, keeps what they have.
	 */
	private function test_a_refund_leaves_other_enrollments_alone(): void {
		$this->boot();
		$this->db->seed_course( [ 'id' => 1, 'slug' => 'sourdough' ] );
		$this->db->seed_course( [ 'id' => 2, 'slug' => 'croissants' ] );

		$this->db->seed_enrollment( [ 'course_id' => 1, 'user_id' => 7, 'order_id' => 500 ] );
		$this->db->seed_enrollment( [ 'course_id' => 2, 'user_id' => 7, 'order_id' => 0 ] );
		$this->db->seed_enrollment( [ 'course_id' => 1, 'user_id' => 8, 'order_id' => 501 ] );

		$revoked = $this->lms->revoke_from_order( 500 );

		$this->assert_same( 1, $revoked, 'only the refunded order is unwound' );
		$this->assert_false( $this->lms->is_enrolled( 1, 7 ), 'the refunded course is gone' );
		$this->assert_true( $this->lms->is_enrolled( 2, 7 ), 'the manually granted course survives' );
		$this->assert_true( $this->lms->is_enrolled( 1, 8 ), "and so does another student's purchase" );
	}

	/**
	 * The enrollment row is deleted rather than flagged, because UNIQUE (course_id, user_id)
	 * plus enroll()'s "return the existing id" would otherwise lock the customer out for good.
	 */
	private function test_revoked_access_can_be_granted_again(): void {
		$this->boot();
		$this->db->seed_course();
		$this->db->seed_enrollment( [ 'order_id' => 500 ] );

		$this->lms->revoke_from_order( 500 );
		$new_id = $this->lms->enroll( 1, 7, 900 );

		$this->assert_true( $new_id > 0, 'buying the course again works' );
		$this->assert_true( $this->lms->is_enrolled( 1, 7 ), 'and restores access' );
	}
}
