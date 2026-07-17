<?php
/**
 * WcagEmExport — Issue #25: Export validation report as WCAG audit document.
 *
 * Produces a WCAG-EM (Evaluation Methodology) compliant audit report for
 * a post or entire site, exportable as JSON or plain text.
 *
 * WCAG-EM structure: https://www.w3.org/TR/WCAG-EM/
 *   - Scope: what was tested
 *   - Sample: pages/posts evaluated
 *   - Audit results: criterion → pass/fail/inapplicable
 *   - Summary and conclusion
 *
 * REST endpoint:
 *   GET /wp-json/gae/v1/wcag-em-report?post_id=123&format=json
 *   GET /wp-json/gae/v1/wcag-em-report?format=json  (site-wide)
 *   → WCAG-EM JSON report
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class WcagEmExport {

    /** WCAG 2.1 criteria we can evaluate. */
    private const CRITERIA = [
        '1.1.1' => [ 'title' => 'Non-text Content',       'level' => 'A',   'rule' => 'require_alt' ],
        '1.2.1' => [ 'title' => 'Audio-only / Video-only', 'level' => 'A',   'rule' => 'require_transcript' ],
        '1.2.2' => [ 'title' => 'Captions (Prerecorded)', 'level' => 'A',   'rule' => 'require_caption_track' ],
        '1.3.1' => [ 'title' => 'Info and Relationships',  'level' => 'A',   'rule' => 'require_hierarchy' ],
        '2.4.6' => [ 'title' => 'Headings and Labels',    'level' => 'AA',  'rule' => 'require_non_empty_text' ],
        '3.3.2' => [ 'title' => 'Labels or Instructions',  'level' => 'A',   'rule' => 'require_link_text' ],
    ];

    /** @var BulkValidator */
    private BulkValidator $bulk;

    public function __construct( ?BulkValidator $bulk = null ) {
        $this->bulk = $bulk ?? new BulkValidator();
    }

    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
    }

    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/wcag-em-report',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restWcagEmReport' ],
                'permission_callback' => function() { return \current_user_can( 'manage_options' ); },
                'args'                => [
                    'post_id' => [ 'sanitize_callback' => 'absint' ],
                    'format'  => [ 'sanitize_callback' => 'sanitize_key' ],
                ],
            ]
        );
    }

    public function restWcagEmReport( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = (int) ( $request->get_param( 'post_id' ) ?? 0 );

        $report = $post_id > 0
            ? $this->generateReportForPost( $post_id )
            : $this->generateSiteReport();

        return new \WP_REST_Response( $report, 200 );
    }

    /**
     * Generate a WCAG-EM report for a single post.
     */
    public function generateReportForPost( int $post_id ): array {
        $post = \get_post( $post_id );
        if ( ! $post ) {
            return $this->emptyReport( "Post #{$post_id} not found." );
        }

        $violations = $this->bulk->scanPostContent( $post->post_content );

        return $this->buildReport(
            [
                [ 'id' => $post_id, 'title' => $post->post_title, 'url' => \get_permalink( $post_id ) ]
            ],
            $violations,
            "Post #{$post_id}: {$post->post_title}"
        );
    }

    /**
     * Generate a WCAG-EM report scanning the most recent 50 published posts.
     */
    public function generateSiteReport(): array {
        $results    = $this->bulk->scanPosts( 'post', 50 );
        $all_viols  = [];
        $sample     = [];

        foreach ( $results['posts'] as $row ) {
            $sample[]  = [ 'id' => $row['post_id'], 'title' => $row['post_title'] ];
            $all_viols = array_merge( $all_viols, $row['violations'] );
        }

        return $this->buildReport( $sample, $all_viols, 'Site-wide WCAG-EM Audit' );
    }

    /**
     * Build a WCAG-EM structured report.
     *
     * @param array[]  $sample     [ ['id', 'title', 'url?'] ]
     * @param array[]  $violations [ ['block', 'message'] ]
     * @param string   $scope_desc
     * @return array
     */
    public function buildReport( array $sample, array $violations, string $scope_desc ): array {
        $rule_violations = [];
        foreach ( $violations as $v ) {
            foreach ( self::CRITERIA as $sc => $meta ) {
                if ( strpos( $v['message'], $meta['rule'] ) !== false ||
                     strpos( $v['block'], 'image' ) !== false && $sc === '1.1.1' ||
                     strpos( $v['block'], 'heading' ) !== false && $sc === '2.4.6' ||
                     strpos( $v['block'], 'button' ) !== false && $sc === '3.3.2' ) {
                    $rule_violations[ $sc ][] = $v['message'];
                }
            }
        }

        $audit_results = [];
        foreach ( self::CRITERIA as $sc => $meta ) {
            $audit_results[] = [
                'criterion'  => "WCAG 2.1 Success Criterion {$sc}",
                'title'      => $meta['title'],
                'level'      => $meta['level'],
                'result'     => empty( $rule_violations[ $sc ] ) ? 'passed' : 'failed',
                'violations' => $rule_violations[ $sc ] ?? [],
            ];
        }

        $failed_count = count( array_filter( $audit_results, fn( $r ) => $r['result'] === 'failed' ) );

        return [
            'wcag_em_version'   => '1.0',
            'wcag_version'      => '2.1',
            'generated_at'      => date( 'c' ),
            'scope'             => [
                'description' => $scope_desc,
                'conformance_target' => 'AA',
            ],
            'sample'            => $sample,
            'audit_results'     => $audit_results,
            'summary'           => [
                'total_criteria' => count( self::CRITERIA ),
                'failed'         => $failed_count,
                'passed'         => count( self::CRITERIA ) - $failed_count,
                'conclusion'     => $failed_count === 0
                    ? 'Conforms to WCAG 2.1 Level AA for evaluated criteria.'
                    : "Does not fully conform: {$failed_count} criterion/criteria failed.",
            ],
        ];
    }

    private function emptyReport( string $reason ): array {
        return [
            'wcag_em_version' => '1.0',
            'error'           => $reason,
            'audit_results'   => [],
        ];
    }
}
