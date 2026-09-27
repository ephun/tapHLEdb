<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

// Functions for querying and displaying particular types of data.
// Many of these will only be used on a single page of the site.

function listApps(bool $showUnapproved): void {
    if ($showUnapproved) {
        $extraColumns = '
            ,
            unapproved_version_counts.count AS unapproved_version_count,
            unapproved_report_counts.count AS unapproved_report_count
        ';
        $extraJoins = '
            LEFT JOIN
                (
                    SELECT
                        COUNT(*) AS count,
                        app_id
                    FROM
                        versions
                    WHERE
                        approved IS NULL
                    GROUP BY
                        app_id
                )
                AS
                    unapproved_version_counts
                ON
                    apps.app_id = unapproved_version_counts.app_id
            LEFT JOIN
                (
                    SELECT
                        COUNT(*) AS count,
                        versions.app_id AS app_id
                    FROM
                        reports
                    LEFT JOIN
                            versions
                        ON
                            versions.version_id = reports.version_id
                    WHERE
                        reports.approved IS NULL
                    GROUP BY
                        versions.app_id
                )
                AS
                    unapproved_report_counts
                ON
                    apps.app_id = unapproved_report_counts.app_id
        ';
    } else {
        $extraColumns = '';
        $extraJoins = '';
    }

    $rows = query('
        SELECT
            apps.app_id AS app_id,
            name,
            MAX(version_summaries.last_updated, version_summaries.last_updated2, apps.created) AS last_updated,
            (approved IS NULL) AS unapproved,
            version_summaries.best_rating AS best_rating,
            extra
            ' . $extraColumns . '
        FROM
            apps
        LEFT JOIN
            (
                SELECT
                    MAX(report_summaries.rating) AS best_rating,
                    MAX(report_summaries.last_updated) AS last_updated,
                    MAX(versions.created) as last_updated2,
                    app_id
                FROM
                    versions
                LEFT JOIN
                    (
                        SELECT
                            MAX(rating) AS rating,
                            MAX(created) AS last_updated,
                            version_id
                        FROM
                            reports
                        WHERE
                            (:show_unapproved OR approved IS NOT NULL) AND
                            COALESCE(json_extract(reports.extra, \'$.verification_type\'), \'compatibility\') <> \'release_verification\'
                        GROUP BY
                            version_id
                    )
                    AS
                        report_summaries
                    ON
                        versions.version_id = report_summaries.version_id
                WHERE
                    (:show_unapproved OR approved IS NOT NULL)
                GROUP BY
                    app_id
            )
            AS
                version_summaries
            ON
                apps.app_id = version_summaries.app_id
        ' . $extraJoins . '
        WHERE
            :show_unapproved OR approved IS NOT NULL
        ORDER BY
            best_rating DESC, name ASC
        ;
    ', [':show_unapproved' => $showUnapproved]);

    foreach ($rows as &$row) {
        $row['compatibility_state'] = latestReleasedCompatibilityStateForApp((int)$row['app_id']);
    }
    unset($row);

    $columns = [
        '_icon' => [
            'name' => 'Icon',
            'image' => '/apps/',
            'image_id' => 'app_id',
        ],
        'name' => [
            'name' => 'App name',
            'link' => ['/apps/', 'app_id', ($showUnapproved ? '?show_unapproved=1' : '')],
        ],
    ];
    $columns += convertExtraFieldInfo(APP_EXTRA_FIELDS, FALSE);
    $columns += [
        'compatibility_state' => [
            'name' => 'Latest released compatibility',
            'compatibility_state' => TRUE,
        ],
        'last_updated' => [
            'name' => 'Last updated',
            'datetime' => TRUE,
        ],
    ];
    $columns += convertExtraFieldInfo(APP_EXTRA_FIELDS, TRUE);
    if ($showUnapproved) {
        $columns += [
            'unapproved_version_count' => [
                'name' => 'Unapproved versions',
                'unapproved_if_nonzero' => TRUE,
            ],
            'unapproved_report_count' => [
                'name' => 'Unapproved reports',
                'unapproved_if_nonzero' => TRUE,
            ],
        ];
    }

    echo "<h3>Legend/Stats</h3>";
    printRatingsLegend($rows);

    echo "<h3>List</h3>";
    printTable($columns, $rows, /* $rowId: */ NULL, /* searchable: */ TRUE);
}

// Returns NULL if the app isn't found.
function getApp(int $id): ?array {
    $rows = query('
        SELECT
            *,
            users.external_username AS created_by_username,
            (approved IS NULL) AS unapproved,
            approved,
            (SELECT external_username FROM users WHERE user_id = apps.approved_by) AS approved_by
        FROM
            apps
        LEFT JOIN
                users
            ON
                users.user_id = apps.created_by
        WHERE
            app_id = :app_id
        ;
    ', [':app_id' => $id]);

    if ($rows === []) {
        return NULL;
    } else {
        return $rows[0];
    }
}

// Helper for printApp()/listVersionsForApp()/listReportsForApp()
function moderationActionButtons(string $urlPrefix, string $idColumn, string $label, bool $topLevel): array {
    $buttons = [];
    if (!$topLevel) {
        $buttons[] = [
            'action_prefix' => $urlPrefix,
            'action_column' => $idColumn,
            'action_suffix' => '/approve?up=1',
            'method' => 'post',
            'label' => '⬆️✅ Approve upwards',
            'onsubmit' => 'return confirm("Are you sure you want to ✅ approve this ' . $label . ' and ⬆️ any higher-level objects?")',
            'depends_on_column' => 'unapproved',
        ];
    }
    $buttons[] = [
        'action_prefix' => $urlPrefix,
        'action_column' => $idColumn,
        'action_suffix' => '/approve',
        'method' => 'post',
        'label' => '✅ Approve',
        'onsubmit' => 'return confirm("Are you sure you want to ✅ approve this ' . $label . '?")',
        'depends_on_column' => 'unapproved',
    ];
    $buttons[] = [
        'action_prefix' => $urlPrefix,
        'action_column' => $idColumn,
        'action_suffix' => '/delete',
        'method' => 'post',
        'label' => '🚮 Delete',
        'onsubmit' => 'return confirm("Are you sure you want to 🚮 DELETE this ' . $label . '? This action CANNOT BE UNDONE.")',
    ];
    return $buttons;
}

// Input comes from getApp().
function printApp(array $appInfo, bool $moderatorView): void {
    $fields = [
        'name' => [
            'name' => 'App name',
            'link' => ['/apps/', 'app_id'],
        ]
    ];
    $fields += convertExtraFieldInfo(APP_EXTRA_FIELDS, FALSE);
    $fields += [
        'created' => [
            'name' => 'First reported',
            'datetime' => TRUE,
        ],
        'created_by_username' => [
            'name' => 'First reported by',
            'external_username' => TRUE,
        ],
    ];
    if ($moderatorView) {
        $fields += [
            'approved' => [
                'name' => 'Approved',
                'datetime' => TRUE,
            ],
            'approved_by' => [
                'name' => 'Approved by',
                'external_username' => TRUE,
            ],
        ];
    }
    $fields += convertExtraFieldInfo(APP_EXTRA_FIELDS, TRUE);
    if ($moderatorView) {
        $fields += [
            '_buttons' => [
                'name' => '',
                'buttons' => moderationActionButtons('/apps/', 'app_id', 'app', /* topLevel: */ TRUE),
            ],
        ];
    }

    printRecord($fields, $appInfo);
}

function printAppForm(): void {
    $fields = [
        'name' => [
            'name' => 'App name',
            'required' => TRUE,
        ]
    ];
    $fields += convertExtraFieldInfo(APP_EXTRA_FIELDS, FALSE);
    $fields += convertExtraFieldInfo(APP_EXTRA_FIELDS, TRUE);
    $fields['icon'] = [
        'name' => 'App icon (PNG or JPEG)',
        'image_upload' => TRUE,
        'required' => TRUE,
    ];

    printRecordForm($fields, 'app');
}

// It is recommended to call this as part of a transaction.
// The result is a the ID of the new app, or NULL if the input is invalid
// in some way.
// The new app has unapproved state, awaiting moderation.
function createApp(array $app): ?int {
    $createdBy = $app['created_by'] ?? NULL;
    if (!is_int($createdBy)) {
        return NULL;
    }

    $name = $app['name'] ?? NULL;
    if (!is_string($name) || $name === "" || !validateInputLength($name)) {
        return NULL;
    }

    $extra = $app['extra'] ?? [];
    if (!is_array($extra)) {
        return NULL;
    }
    if (!validateExtraFields(APP_EXTRA_FIELDS, $extra)) {
        return NULL;
    }
    $icon = decodeUploadedImage($app['icon'] ?? NULL, 512 * 1000);
    if ($icon === NULL) {
        return NULL;
    }
    $extra = json_encode($extra);

    $rows = query('
        INSERT INTO
            apps(
                created,
                created_by,
                name,
                extra
            )
        VALUES
            (
                datetime(),
                :created_by,
                :name,
                :extra
            )
        ;
    ', [
        ':created_by' => $createdBy,
        ':name' => $name,
        ':extra' => $extra,
    ]);
    $appId = dbGetInsertedId();
    query('INSERT INTO app_icons(app_id, mime_type, image) VALUES(:app_id, :mime_type, :image);', [
        ':app_id' => $appId,
        ':mime_type' => $icon['mime_type'],
        ':image' => $icon['image'],
    ]);
    return $appId;
}

function listEndUserVersionsForApp(int $appId): void {
    $rows = query('SELECT version_id,name,extra FROM versions
        WHERE app_id=:app_id AND approved IS NOT NULL ORDER BY name;', [':app_id'=>$appId]);
    foreach ($rows as &$row) {
        $row['compatibility_state']=latestReleasedCompatibilityStateForVersion((int)$row['version_id']);
        $platformRows=query('SELECT compatibility_state,json_extract(extra,\'$.platform\') AS platform
            FROM reports WHERE version_id=:version AND approved IS NOT NULL
              AND json_extract(extra,\'$.verification_type\')=\'compatibility\'
              AND json_extract(extra,\'$.release_channel\')=\'normal_release\'
            ORDER BY json_extract(extra,\'$.tested_at\') DESC,report_id DESC;', [':version'=>$row['version_id']]);
        $states=[];
        foreach($platformRows as $platformRow){$platform=(string)$platformRow['platform'];if(!isset($states[$platform]))$states[$platform]=compatibilityStateSymbol((string)$platformRow['compatibility_state']);}
        ksort($states);
        $row['platform_summary']=implode(', ',array_map(fn($platform,$state)=>$platform.': '.$state,array_keys($states),array_values($states)));
    }
    unset($row);
    printTable([
        'name'=>['name'=>'Version'],
        'short_version'=>['name'=>'Short version','extra'=>TRUE],
        'compatibility_state'=>['name'=>'Latest released compatibility','compatibility_state'=>TRUE],
        'platform_summary'=>['name'=>'Platform exceptions'],
    ],$rows);
}

function latestReleasedCompatibilityStateForApp(int $appId): string {
    $rows = query('SELECT reports.compatibility_state
        FROM reports JOIN versions ON versions.version_id = reports.version_id
        WHERE versions.app_id = :app_id AND reports.approved IS NOT NULL
          AND versions.approved IS NOT NULL
          AND json_extract(reports.extra, \'$.verification_type\') = \'compatibility\'
          AND json_extract(reports.extra, \'$.release_channel\') = \'normal_release\'
        ORDER BY json_extract(reports.extra, \'$.tested_at\') DESC, reports.report_id DESC LIMIT 1;',
        [':app_id' => $appId]);
    return $rows === [] ? '?????' : (string)$rows[0]['compatibility_state'];
}

function latestReleasedCompatibilityStateForVersion(int $versionId): string {
    $rows = query('SELECT compatibility_state FROM reports
        WHERE version_id = :version_id AND approved IS NOT NULL
          AND json_extract(extra, \'$.verification_type\') = \'compatibility\'
          AND json_extract(extra, \'$.release_channel\') = \'normal_release\'
        ORDER BY json_extract(extra, \'$.tested_at\') DESC, report_id DESC LIMIT 1;',
        [':version_id' => $versionId]);
    return $rows === [] ? '?????' : (string)$rows[0]['compatibility_state'];
}

function decodeUploadedImage($value, int $maximumBytes): ?array {
    if (!is_string($value) || !preg_match('#\Adata:(image/(?:png|jpeg));base64,(.+)\z#s', $value, $matches)) {
        return NULL;
    }
    $image = base64_decode($matches[2], TRUE);
    if ($image === FALSE || $image === '' || strlen($image) > $maximumBytes) {
        return NULL;
    }
    if (($matches[1] === 'image/png' && !str_starts_with($image, "\x89PNG\r\n\x1a\n")) ||
        ($matches[1] === 'image/jpeg' && !str_starts_with($image, "\xFF\xD8\xFF"))) {
        return NULL;
    }
    return ['mime_type' => $matches[1], 'image' => $image];
}

function getAppIcon(int $appId): ?array {
    $rows = query('SELECT mime_type, image FROM app_icons WHERE app_id = :app_id;', [':app_id' => $appId]);
    return $rows === [] ? NULL : $rows[0];
}

// It is recommended to call this as part of a transaction.
function approveApp(int $appId, int $approvedByUserId): void {
    query('
        UPDATE
            apps
        SET
            approved = datetime(),
            approved_by = :approved_by_user_id
        WHERE
            app_id = :app_id AND
            approved IS NULL AND
            EXISTS(SELECT 1 FROM app_icons WHERE app_icons.app_id = apps.app_id)
        ;
    ', [
        ':app_id' => $appId,
        ':approved_by_user_id' => $approvedByUserId,
    ]);
}

// This must be called as part of a transaction!
// It deletes not only the app, but also its connected versions and reports!
// There is no audit log or undo!
function deleteApp(int $appId): void {
    query('DELETE FROM developer_notes WHERE app_id=:app_id
        OR version_id IN (SELECT version_id FROM versions WHERE app_id=:app_id)
        OR report_id IN (SELECT reports.report_id FROM reports JOIN versions ON versions.version_id=reports.version_id WHERE versions.app_id=:app_id);', [':app_id'=>$appId]);
    query('DELETE FROM app_icons WHERE app_id = :app_id;', [':app_id' => $appId]);
    query('
        DELETE FROM
            report_screenshots
        WHERE
            EXISTS (
                SELECT
                    1
                FROM
                    reports
                LEFT JOIN
                        versions
                    ON
                        reports.version_id = versions.version_id
                WHERE
                    reports.report_id = report_screenshots.report_id AND
                    versions.app_id = :app_id
            )
        ;
    ', [':app_id' => $appId]);
    query('
        DELETE FROM
            reports
        WHERE
            EXISTS (
                SELECT
                    1
                FROM
                    versions
                WHERE
                    version_id = reports.version_id AND
                    app_id = :app_id
            )
        ;
    ', [':app_id' => $appId]);
    query('
        DELETE FROM
            versions
        WHERE
            app_id = :app_id
        ;
    ', [':app_id' => $appId]);
    query('
        DELETE FROM
            apps
        WHERE
            app_id = :app_id
        ;
    ', [':app_id' => $appId]);
    cleanUpUsers();
}

// Returns NULL if the version isn't found.
function getVersion(int $id): ?array {
    $rows = query('
        SELECT
            *
        FROM
            versions
        WHERE
            version_id = :version_id
        ;
    ', [':version_id' => $id]);

    if ($rows === []) {
        return NULL;
    } else {
        return $rows[0];
    }
}

function listVersionsForApp(int $appId, bool $showUnapproved, bool $moderatorView): void {
    if ($moderatorView) {
        $extraColumns = '
            ,
            approved,
            (SELECT external_username FROM users WHERE user_id = versions.approved_by) AS approved_by
        ';
    } else {
        $extraColumns = '';
    }

    $rows = query('
        SELECT
            versions.version_id AS version_id,
            name,
            report_summaries.rating AS best_rating,
            MAX(report_summaries.last_updated, versions.created) AS last_updated,
            users.external_username AS created_by_username,
            (approved IS NULL) AS unapproved,
            extra
            ' . $extraColumns . '
        FROM
            versions
        LEFT JOIN
            (
                SELECT
                    MAX(rating) AS rating,
                    MAX(created) AS last_updated,
                    version_id
                FROM
                    reports
                WHERE
                    (:show_unapproved OR approved IS NOT NULL) AND
                    COALESCE(json_extract(reports.extra, \'$.verification_type\'), \'compatibility\') <> \'release_verification\'
                GROUP BY
                    version_id
            )
            AS
                report_summaries
            ON
                versions.version_id = report_summaries.version_id
        LEFT JOIN
                users
            ON
                users.user_id = versions.created_by
        WHERE
            app_id = :app_id AND
            (:show_unapproved OR approved IS NOT NULL)
        ORDER BY
            name ASC, rating DESC
        ;
    ', [
        ':app_id' => $appId,
        ':show_unapproved' => $showUnapproved,
    ]);
    foreach ($rows as &$row) {
        $row['compatibility_state'] = latestReleasedCompatibilityStateForVersion((int)$row['version_id']);
    }
    unset($row);

    $columns = [
        'name' => [
            'name' => 'Version number',
        ],
    ];
    $columns += convertExtraFieldInfo(VERSION_EXTRA_FIELDS, FALSE);
    $columns += [
        'compatibility_state' => [
            'name' => 'Latest released compatibility',
            'compatibility_state' => TRUE,
        ],
        'last_updated' => [
            'name' => 'Last updated',
            'datetime' => TRUE,
        ],
        'created_by_username' => [
            'name' => 'First reported by',
            'external_username' => TRUE,
        ],
    ];
    if ($moderatorView) {
        $columns += [
            'approved' => [
                'name' => 'Approved',
                'datetime' => TRUE,
            ],
            'approved_by' => [
                'name' => 'Approved by',
                'external_username' => TRUE,
            ],
        ];
    }
    $columns += convertExtraFieldInfo(VERSION_EXTRA_FIELDS, TRUE);

    $buttons = [
        [
            'action' => '/reports/new',
            'method' => 'get',
            'label' => 'Submit report for this version',
            'param_name' => 'version',
            'param_column' => 'version_id',
        ]
    ];
    if ($moderatorView) {
        foreach (moderationActionButtons('/versions/', 'version_id', 'version', /* topLevel: */ FALSE) as $button) {
            $buttons[] = $button;
        }
        $buttons[] = [
            'label' => '🎯 Reparent here',
            'param_name' => 'version',
            'param_column' => 'version_id',
            'disabled' => TRUE,
            'class' => 'reparent-target',
        ];
    }

    $columns += [
        '_buttons' => [
            'name' => '',
            'buttons' => $buttons,
        ],
    ];

    printTable($columns, $rows, ['version-', 'version_id']);
}

function printVersionForm(): void {
    $fields = [
        'name' => [
            'name' => 'Version number',
            'required' => TRUE,
        ]
    ];
    $fields += convertExtraFieldInfo(VERSION_EXTRA_FIELDS, FALSE);
    $fields += convertExtraFieldInfo(VERSION_EXTRA_FIELDS, TRUE);

    printRecordForm($fields, 'version');
}

// It is recommended to call this as part of a transaction.
// The result is a the ID of the new version, or NULL if the input is invalid
// in some way.
// The new version has unapproved state, awaiting moderation.
function createVersion(array $version): ?int {
    $appId = $version['app_id'] ?? NULL;
    if (!is_int($appId)) {
        return NULL;
    }

    $createdBy = $version['created_by'] ?? NULL;
    if (!is_int($createdBy)) {
        return NULL;
    }

    $name = $version['name'] ?? NULL;
    if (!is_string($name) || $name === "" || !validateInputLength($name)) {
        return NULL;
    }

    $extra = $version['extra'] ?? [];
    if (!is_array($extra)) {
        return NULL;
    }
    if (!validateExtraFields(VERSION_EXTRA_FIELDS, $extra)) {
        return NULL;
    }
    $extra = json_encode($extra);

    $rows = query('
        INSERT INTO
            versions(
                app_id,
                created,
                created_by,
                name,
                extra
            )
        VALUES
            (
                :app_id,
                datetime(),
                :created_by,
                :name,
                :extra
            )
        ;
    ', [
        ':app_id' => $appId,
        ':created_by' => $createdBy,
        ':name' => $name,
        ':extra' => $extra,
    ]);
    return dbGetInsertedId();
}

// It is recommended to call this as part of a transaction.
function approveVersion(int $versionId, int $approvedByUserId): void {
    query('
        UPDATE
            versions
        SET
            approved = datetime(),
            approved_by = :approved_by_user_id
        WHERE
            version_id = :version_id AND
            approved IS NULL
        ;
    ', [
        ':version_id' => $versionId,
        ':approved_by_user_id' => $approvedByUserId,
    ]);
}

// This must be called as part of a transaction!
// It deletes not only the version, but also its connected reports!
// There is no audit log or undo!
function deleteVersion(int $versionId): void {
    query('DELETE FROM developer_notes WHERE version_id=:version_id
        OR report_id IN (SELECT report_id FROM reports WHERE version_id=:version_id);', [':version_id'=>$versionId]);
    query('
        DELETE FROM
            report_screenshots
        WHERE
            EXISTS (
                SELECT
                    1
                FROM
                    reports
                WHERE
                    reports.report_id = report_screenshots.report_id AND
                    version_id = :version_id
            )
        ;
    ', [':version_id' => $versionId]);
    query('
        DELETE FROM
            reports
        WHERE
            version_id = :version_id
        ;
    ', [':version_id' => $versionId]);
    query('
        DELETE FROM
            versions
        WHERE
            version_id = :version_id
        ;
    ', [':version_id' => $versionId]);
    cleanUpUsers();
}

// Returns NULL if the report isn't found.
function getReport(int $id): ?array {
    $rows = query('
        SELECT
            reports.*,
            versions.app_id
        FROM
            reports
        LEFT JOIN
                versions
            ON
                versions.version_id = reports.version_id
        WHERE
            report_id = :report_id
        ;
    ', [':report_id' => $id]);

    if ($rows === []) {
        return NULL;
    } else {
        return $rows[0];
    }
}

// Returns NULL if the report isn't found, or has no screenshot.
// The result is a binary blob of JPEG data.
function getReportScreenshotImage(int $id): ?string {
    $rows = query('
        SELECT
            image
        FROM
            report_screenshots
        WHERE
            report_id = :report_id
        ;
    ', [':report_id' => $id]);

    if ($rows === []) {
        return NULL;
    } else {
        return $rows[0]['image'];
    }
}

function listReportsForApp(int $appId, bool $showUnapproved, bool $moderatorView): void {
    if ($moderatorView) {
        $extraColumns = '
            ,
            reports.approved AS approved,
            (SELECT external_username FROM users WHERE user_id = reports.approved_by) AS approved_by
        ';
    } else {
        $extraColumns = '';
    }

    $rows = query('
        SELECT
            reports.report_id AS report_id,
            versions.version_id AS version_id,
            versions.name AS version_name,
            reports.rating AS rating,
            reports.compatibility_state AS compatibility_state,
            reports.created AS created,
            users.external_username AS created_by_username,
            (reports.approved IS NULL) AS unapproved,
            reports.extra AS extra,
            EXISTS (SELECT 1 FROM report_screenshots WHERE report_screenshots.report_id = reports.report_id) AS has_screenshot,
            EXISTS (
                SELECT 1 FROM reports AS conflicting
                WHERE conflicting.version_id = reports.version_id
                  AND conflicting.report_id <> reports.report_id
                  AND conflicting.approved IS NOT NULL
                  AND json_extract(conflicting.extra, \'$.verification_type\') = \'compatibility\'
                  AND json_extract(conflicting.extra, \'$.taphle_commit\') = json_extract(reports.extra, \'$.taphle_commit\')
                  AND json_extract(conflicting.extra, \'$.platform\') = json_extract(reports.extra, \'$.platform\')
                  AND conflicting.compatibility_state <> reports.compatibility_state
            ) AS conflict
            ' . $extraColumns . '
        FROM
            reports
        LEFT JOIN
                versions
            ON
                reports.version_id = versions.version_id
        LEFT JOIN
                users
            ON
                users.user_id = reports.created_by
        WHERE
            app_id = :app_id AND
            (:show_unapproved OR reports.approved IS NOT NULL)
        ORDER BY
            reports.created DESC
        ;
    ', [
        ':app_id' => $appId,
        ':show_unapproved' => $showUnapproved,
    ]);

    $columns = [
        'version_name' => [
            'name' => 'Version number',
            'link' => ['#version-', 'version_id'],
        ],
    ];
    $columns += convertExtraFieldInfo(REPORT_EXTRA_FIELDS, FALSE);
    $columns += [
        'compatibility_state' => [
            'name' => 'Compatibility state',
            'compatibility_state' => TRUE,
        ],
        'created' => [
            'name' => 'Reported',
            'datetime' => TRUE,
        ],
        'created_by_username' => [
            'name' => 'Reported by',
            'external_username' => TRUE,
        ],
    ];
    if ($moderatorView) {
        $columns += [
            'approved' => [
                'name' => 'Approved',
                'datetime' => TRUE,
            ],
            'approved_by' => [
                'name' => 'Approved by',
                'external_username' => TRUE,
            ],
        ];
    }

    $columns += convertExtraFieldInfo(REPORT_EXTRA_FIELDS, TRUE);
    $columns += [
        'has_screenshot' => [
            'name' => 'Screenshot',
            'link' => ['#report-screenshot-', 'report_id'],
            'link_label' => 'View',
            'link_if' => 'has_screenshot',
        ],
        'conflict' => [
            'name' => 'Conflict',
            'options' => [0 => '', 1 => '⚠ conflicting historical evidence'],
        ],
    ];

    if ($moderatorView) {
        $buttons = moderationActionButtons('/reports/', 'report_id', 'report', /* topLevel: */ FALSE);
        $buttons[] = [
            'label' => '↗️ Reparent',
            'action_prefix' => '/reports/',
            'action_column' => 'report_id',
            'action_suffix' => '/reparent',
            'method' => 'post',
            'disabled' => TRUE,
            'class' => 'reparent-source',
        ];

        $columns += [
            '_buttons' => [
                'name' => '',
                'buttons' => $buttons,
            ],
        ];
    }

    printTable($columns, $rows, ['report-', 'report_id']);
}

function listReportScreenshotsForApp(int $appId, bool $showUnapproved, bool $moderatorView): void {
    $rows = query('
        SELECT
            report_id
        FROM
            reports
        LEFT JOIN
                versions
            ON
                reports.version_id = versions.version_id
        WHERE
            app_id = :app_id AND
            (:show_unapproved OR reports.approved IS NOT NULL) AND
            EXISTS (SELECT 1 FROM report_screenshots WHERE report_screenshots.report_id = reports.report_id)
        ORDER BY
            reports.created DESC
        ;
    ', [
        ':app_id' => $appId,
        ':show_unapproved' => $showUnapproved,
    ]);

    foreach ($rows as $row) {
        $reportId = (string)$row['report_id'];
        echo '<figure id="', htmlspecialchars('report-screenshot-' . $reportId), '">';
        echo '<img src="', htmlspecialchars(url('/reports/' . $reportId . '/screenshot')), '" alt="Screenshot">';
        echo '<figcaption>';
        if ($moderatorView) {
            printButtonForm([
                'action' => '/reports/' . $reportId . '/screenshot/delete',
                'method' => 'post',
                'label' => '🚮 Delete screenshot',
                'onsubmit' => 'return confirm("Are you sure you want to 🚮 DELETE this report\'s screenshot? This action CANNOT BE UNDONE.")',
            ]);
        }
        echo '<a href="#report-', htmlspecialchars($reportId), '">Go to report</a></figcaption>';
        echo '</figure>';
    }
}

function printReportForm(): void {
    $fields = convertExtraFieldInfo(REPORT_EXTRA_FIELDS, FALSE);
    unset($fields['source_class'], $fields['source_subtype'], $fields['source_identity']);
    $fields += [
        'compatibility_state' => [
            'name' => 'Compatibility state',
            'compatibility_state' => TRUE,
            'required' => TRUE,
        ],
    ];
    $fields += convertExtraFieldInfo(REPORT_EXTRA_FIELDS, TRUE);
    unset($fields['source_class'], $fields['source_subtype'], $fields['source_identity']);

    if (REPORT_SCREENSHOTS_ALLOWED) {
        $fields += [
            'screenshot' => [
                'name' => 'Screenshot',
                'image_upload' => TRUE,
            ],
        ];
    }

    printRecordForm($fields, 'report');
}

// It is recommended to call this as part of a transaction.
// The result is a the ID of the new report, or NULL if the input is invalid
// in some way.
// The new report has unapproved state, awaiting moderation.
function createReport(array $report): ?int {
    $createdBy = $report['created_by'] ?? NULL;
    if (!is_int($createdBy)) {
        return NULL;
    }

    $compatibilityState = $report['compatibility_state'] ?? NULL;
    if (!is_string($compatibilityState)) {
        return NULL;
    }
    $rating = compatibilityStateStarCount($compatibilityState);
    if ($rating === NULL) {
        return NULL;
    }

    $versionId = $report['version_id'] ?? NULL;
    if (!is_int($versionId)) {
        return NULL;
    }

    $screenshot = $report['screenshot'] ?? '';
    if ($screenshot === '') {
        $screenshot = NULL;
    // This should be a base64 data URI for a JPEG image, which will have been
    // compressed on the client by the code in script.js, which uses 80% JPEG
    // quality and limits the size to at most 640 × 640 pixels. Cursory testing
    // suggests the result is usually around 50KB and, rarely, as high as 96KB.
    // I'm not sure what the actual maximum is, but 50% more than the largest
    // size I've seen is probably a reasonable limit. The (8/6) is to compensate
    // for base64 encoding.
    } else if (!is_string($screenshot) ||
        strlen($screenshot) > (150 * 1000 * (8/6)) ||
        !str_starts_with($screenshot, "data:image/jpeg;base64,")) {
        return NULL;
    } else {
        $screenshot = substr($screenshot, strlen("data:image/jpeg;base64,"));
        $screenshot = base64_decode($screenshot, /* strict: */ TRUE);
        if ($screenshot === FALSE) {
            return NULL;
        }
    }

    $extra = $report['extra'] ?? [];
    if (!is_array($extra)) {
        return NULL;
    }
    $version = getVersion($versionId);
    $versionExtra = $version === NULL ? NULL : json_decode((string)$version['extra'], TRUE);
    if (!is_array($versionExtra) ||
        ($versionExtra['app_artifact_sha256'] ?? NULL) !== ($extra['app_artifact_sha256'] ?? NULL) ||
        !validateExtraFields(REPORT_EXTRA_FIELDS, $extra) ||
        !validateReportPolicy($compatibilityState, $extra, $screenshot !== NULL)) {
        return NULL;
    }
    $extra = json_encode($extra);

    $rows = query('
        INSERT INTO
            reports(
                version_id,
                created,
                created_by,
                rating,
                compatibility_state,
                extra
            )
        VALUES
            (
                :version_id,
                datetime(),
                :created_by,
                :rating,
                :compatibility_state,
                :extra
            )
        ;
    ', [
        ':version_id' => $versionId,
        ':created_by' => $createdBy,
        ':rating' => $rating,
        ':compatibility_state' => $compatibilityState,
        ':extra' => $extra,
    ]);
    $reportId = dbGetInsertedId();

    if ($screenshot !== NULL) {
        $rows = query('
            INSERT INTO
                report_screenshots(
                    report_id,
                    image
                )
            VALUES
                (
                    :report_id,
                    :image
                )
            ;
        ', [
            ':report_id' => $reportId,
            ':image' => $screenshot,
        ]);
    }

    return $reportId;
}

function userHasUnapprovedItems(int $userId): bool {
    $rows = query('
        SELECT
            (EXISTS(
                SELECT
                    1
                FROM
                    apps
                WHERE
                    approved IS NULL AND created_by = :user_id
            ) OR EXISTS(
                SELECT
                    1
                FROM
                    versions
                WHERE
                    approved IS NULL AND created_by = :user_id
            ) OR EXISTS(
                SELECT
                    1
                FROM
                    reports
                WHERE
                    approved IS NULL AND created_by = :user_id
            )) AS user_has_unapproved_items
        ;
    ', [':user_id' => $userId]);
    return (bool)$rows[0]['user_has_unapproved_items'];
}

// It is recommended to call this as part of a transaction.
function reportSatisfiesCurrentPolicy(int $reportId): bool {
    $report = getReport($reportId);
    if ($report === NULL) return FALSE;
    $extra = json_decode((string)$report['extra'], TRUE);
    $version = getVersion((int)$report['version_id']);
    $versionExtra = $version === NULL ? NULL : json_decode((string)$version['extra'], TRUE);
    if (!is_array($extra) || !is_array($versionExtra) ||
        ($extra['app_artifact_sha256'] ?? NULL) !== ($versionExtra['app_artifact_sha256'] ?? NULL)) {
        return FALSE;
    }
    return validateReportPolicy((string)$report['compatibility_state'], $extra, getReportScreenshotImage($reportId) !== NULL);
}

function approveReport(int $reportId, int $approvedByUserId): void {
    if (!reportSatisfiesCurrentPolicy($reportId)) return;
    query('
        UPDATE
            reports
        SET
            approved = datetime(),
            approved_by = :approved_by_user_id
        WHERE
            report_id = :report_id AND
            approved IS NULL
        ;
    ', [
        ':report_id' => $reportId,
        ':approved_by_user_id' => $approvedByUserId,
    ]);
}

// This must be called as part of a transaction!
// There is no audit log or undo!
function deleteReport(int $reportId): void {
    query('DELETE FROM developer_notes WHERE report_id=:report_id;', [':report_id'=>$reportId]);
    query('
        DELETE FROM
            report_screenshots
        WHERE
            report_id = :report_id
        ;
    ', [':report_id' => $reportId]);
    query('
        DELETE FROM
            reports
        WHERE
            report_id = :report_id
        ;
    ', [':report_id' => $reportId]);
    cleanUpUsers();
}

// It is recommended to call this as part of a transaction.
// There is no audit log or undo!
function deleteReportScreenshot(int $reportId): void {
    $report = getReport($reportId);
    if ($report !== NULL) {
        $extra = json_decode((string)$report['extra'], TRUE);
        if (is_array($extra) && !validateReportPolicy((string)$report['compatibility_state'], $extra, FALSE)) {
            return;
        }
    }
    query('
        DELETE FROM
            report_screenshots
        WHERE
            report_id = :report_id
        ;
    ', [':report_id' => $reportId]);
}

// It is recommended to call this as part of a transaction (in particular, to
// ensure this will not orphan the report).
// There is no audit log or undo!
function reparentReport(int $reportId, int $versionId): void {
    $report = getReport($reportId);
    $version = getVersion($versionId);
    $reportExtra = $report === NULL ? NULL : json_decode((string)$report['extra'], TRUE);
    $versionExtra = $version === NULL ? NULL : json_decode((string)$version['extra'], TRUE);
    if (!is_array($reportExtra) || !is_array($versionExtra) ||
        ($reportExtra['app_artifact_sha256'] ?? NULL) !== ($versionExtra['app_artifact_sha256'] ?? NULL)) {
        return;
    }
    query('
        UPDATE
            reports
        SET
            version_id = :version_id
        WHERE
            report_id = :report_id
        ;
    ', [
        ':report_id' => $reportId,
        ':version_id' => $versionId,
    ]);
}

// Moderator-only duplicate correction helpers. Reports remain intact.
function versionsHaveMatchingProvenance(int $sourceVersionId, int $targetVersionId): bool {
    $source = getVersion($sourceVersionId);
    $target = getVersion($targetVersionId);
    if ($source === NULL || $target === NULL || (int)$source['app_id'] !== (int)$target['app_id']) {
        return FALSE;
    }
    $sourceExtra = json_decode((string)$source['extra'], TRUE);
    $targetExtra = json_decode((string)$target['extra'], TRUE);
    return is_array($sourceExtra) && is_array($targetExtra) &&
        ($sourceExtra['bundle_version'] ?? NULL) === ($targetExtra['bundle_version'] ?? NULL) &&
        strtolower((string)($sourceExtra['app_artifact_sha256'] ?? '')) ===
            strtolower((string)($targetExtra['app_artifact_sha256'] ?? ''));
}

function mergeVersionInto(int $sourceVersionId, int $targetVersionId): bool {
    if (!versionsHaveMatchingProvenance($sourceVersionId, $targetVersionId)) {
        return FALSE;
    }
    query('UPDATE reports SET version_id=:target WHERE version_id=:source;', [':target'=>$targetVersionId, ':source'=>$sourceVersionId]);
    query('UPDATE developer_notes SET version_id=:target WHERE version_id=:source;', [':target'=>$targetVersionId, ':source'=>$sourceVersionId]);
    query('DELETE FROM versions WHERE version_id=:source;', [':source'=>$sourceVersionId]);
    return TRUE;
}

function mergeAppInto(int $sourceAppId, int $targetAppId): void {
    $versions = query('SELECT version_id,extra FROM versions WHERE app_id=:source ORDER BY version_id;', [':source'=>$sourceAppId]);
    foreach ($versions as $version) {
        $extra = json_decode((string)$version['extra'], TRUE);
        $targetVersion = NULL;
        if (is_array($extra)) {
            $matches = query('SELECT version_id FROM versions WHERE app_id=:app
                AND json_extract(extra, \'$.bundle_version\')=:build
                AND lower(json_extract(extra, \'$.app_artifact_sha256\'))=lower(:hash)
                ORDER BY version_id LIMIT 1;', [
                ':app'=>$targetAppId,
                ':build'=>$extra['bundle_version'] ?? NULL,
                ':hash'=>$extra['app_artifact_sha256'] ?? NULL,
            ]);
            if ($matches !== []) $targetVersion = (int)$matches[0]['version_id'];
        }
        if ($targetVersion === NULL) {
            query('UPDATE versions SET app_id=:target WHERE version_id=:version;', [':target'=>$targetAppId, ':version'=>$version['version_id']]);
        } else {
            query('UPDATE reports SET version_id=:target WHERE version_id=:source;', [':target'=>$targetVersion, ':source'=>$version['version_id']]);
            query('UPDATE developer_notes SET version_id=:target WHERE version_id=:source;', [':target'=>$targetVersion, ':source'=>$version['version_id']]);
            query('DELETE FROM versions WHERE version_id=:source;', [':source'=>$version['version_id']]);
        }
    }
    query('UPDATE developer_notes SET app_id=:target WHERE app_id=:source;', [':target'=>$targetAppId, ':source'=>$sourceAppId]);
    query('INSERT OR IGNORE INTO app_icons(app_id,mime_type,image) SELECT :target,mime_type,image FROM app_icons WHERE app_id=:source;', [':target'=>$targetAppId, ':source'=>$sourceAppId]);
    query('DELETE FROM app_icons WHERE app_id=:source;', [':source'=>$sourceAppId]);
    query('DELETE FROM apps WHERE app_id=:source;', [':source'=>$sourceAppId]);
}

function createDeveloperNote(array $note, bool $trusted = FALSE): ?int {
    $createdBy = $note['created_by'] ?? NULL;
    $body = $note['body'] ?? NULL;
    if (!is_int($createdBy) || !is_string($body) || trim($body) === '' || strlen($body) > 8000) return NULL;
    $targets = ['app_id'=>$note['app_id'] ?? NULL,'version_id'=>$note['version_id'] ?? NULL,'report_id'=>$note['report_id'] ?? NULL];
    $present = array_filter($targets, fn($value)=>is_int($value));
    if (count($present) !== 1) return NULL;
    if (isset($present['app_id']) && getApp($present['app_id']) === NULL) return NULL;
    if (isset($present['version_id']) && getVersion($present['version_id']) === NULL) return NULL;
    if (isset($present['report_id']) && getReport($present['report_id']) === NULL) return NULL;
    query('INSERT INTO developer_notes(created,created_by,approved,approved_by,app_id,version_id,report_id,body)
        VALUES(datetime(),:created_by,:approved,:approved_by,:app_id,:version_id,:report_id,:body);', [
        ':created_by'=>$createdBy, ':approved'=>$trusted ? gmdate('Y-m-d H:i:s') : NULL, ':approved_by'=>$trusted ? $createdBy : NULL,
        ':app_id'=>$targets['app_id'], ':version_id'=>$targets['version_id'], ':report_id'=>$targets['report_id'], ':body'=>trim($body),
    ]);
    return dbGetInsertedId();
}

function getDeveloperNote(int $noteId): ?array {
    $rows=query('SELECT * FROM developer_notes WHERE note_id=:id;',[':id'=>$noteId]);
    return $rows===[]?NULL:$rows[0];
}
function approveDeveloperNote(int $noteId,int $userId):void {
    query('UPDATE developer_notes SET approved=datetime(),approved_by=:user WHERE note_id=:id AND approved IS NULL;',[':user'=>$userId,':id'=>$noteId]);
}
function deleteDeveloperNote(int $noteId):void { query('DELETE FROM developer_notes WHERE note_id=:id;',[':id'=>$noteId]); }

function listDeveloperNotesForApp(int $appId, bool $showUnapproved): void {
    $rows = query('SELECT developer_notes.*, (developer_notes.approved IS NULL) AS unapproved, users.external_username AS created_by_username,
        versions.name AS version_name, reports.report_id AS attached_report
        FROM developer_notes
        LEFT JOIN users ON users.user_id=developer_notes.created_by
        LEFT JOIN versions ON versions.version_id=developer_notes.version_id
        LEFT JOIN reports ON reports.report_id=developer_notes.report_id
        LEFT JOIN versions report_versions ON report_versions.version_id=reports.version_id
        WHERE (developer_notes.app_id=:app OR versions.app_id=:app OR report_versions.app_id=:app)
          AND (:show OR developer_notes.approved IS NOT NULL)
        ORDER BY developer_notes.created DESC;', [':app'=>$appId,':show'=>$showUnapproved]);
    $fields = [
        'body'=>['name'=>'Note'], 'version_name'=>['name'=>'Version'], 'attached_report'=>['name'=>'Report/test run'],
        'created'=>['name'=>'Created','datetime'=>TRUE], 'created_by_username'=>['name'=>'Author','external_username'=>TRUE],
    ];
    if ($showUnapproved) {
        $fields['_buttons']=['name'=>'','buttons'=>moderationActionButtons('/notes/','note_id','note',FALSE)];
    }
    printTable($fields, $rows);
}

// Gets the internal user ID using an external user ID. See createOrGetUserId()
// for more detail. Returns NULL if there is no internal user ID for this user.
function getUserId(string $externalUserId): ?int {
    $rows = query('
        SELECT
            user_id
        FROM
            users
        WHERE
            external_user_id = :external_user_id
        ;
    ', [':external_user_id' => $externalUserId]);

    if ($rows !== []) {
        return (int)$rows[0]['user_id'];
    } else {
        return NULL;
    }
}

// Register the (external user ID, external username) pair in the database, if
// it doesn't already exist, and return the internal user ID. Note that the
// external user ID and username must be prefixed with the service they're from.
//
// This should only be called as part of a transaction that adds some other
// record referencing the user ID, so that users' identities are not tracked
// unless they have chosen to do submit something, at which point they are
// warned of the tracking. (See templates/new_report.phpt.)
function createOrGetUserId(string $externalUserId, string $externalUsername): int {
    $existing = getUserId($externalUserId);
    if ($existing !== NULL) {
        return $existing;
    }

    $rows = query('
        INSERT INTO
            users(
                external_user_id,
                external_username
            )
        VALUES
            (
                :external_user_id,
                :external_username
            )
        ;
    ', [
        ':external_user_id' => $externalUserId,
        ':external_username' => $externalUsername
    ]);
    return dbGetInsertedId();
}

// If there is an external username associated with this external user ID in the
// database, update the username. Otherwise, do nothing. Note that the external
// user ID and username must be prefixed with the service they're from.
//
// This should be done when the user logs in, and they need to be informed of
// this consequence before logging in (see templates/new_report.phpt).
// This begins and ends a transaction!
function updateUsernameForUser(string $externalUserId, string $externalUsername): void {
    beginTransaction();
    query('
        UPDATE
            users
        SET
            external_username = :external_username
        WHERE
            external_user_id = :external_user_id AND
            external_username <> :external_username
        ;
    ', [
        ':external_user_id' => $externalUserId,
        ':external_username' => $externalUsername
    ]);
    commitTransaction();
}

// This must be called as part of a transaction!
// Helper function for deleteApp(), deleteVersion() and deleteReport():
// Remove users from the database if they're no longer referenced by anything.
// This is in line with the principles of createOrGetUserId().
function cleanUpUsers(): void {
    query('
        DELETE FROM
            users
        WHERE
                (NOT EXISTS(
                    SELECT
                        1
                    FROM
                        apps
                    WHERE
                        created_by = users.user_id OR
                        approved_by = users.user_id
                ))
            AND
                (NOT EXISTS(
                    SELECT
                        1
                    FROM
                        versions
                    WHERE
                        created_by = users.user_id OR
                        approved_by = users.user_id
                ))
            AND
                (NOT EXISTS(
                    SELECT
                        1
                    FROM
                        reports
                    WHERE
                        created_by = users.user_id OR
                        approved_by = users.user_id
                ))
            AND
                (NOT EXISTS(
                    SELECT 1 FROM developer_notes
                    WHERE created_by = users.user_id OR approved_by = users.user_id
                ))
        ;
    ');
}
