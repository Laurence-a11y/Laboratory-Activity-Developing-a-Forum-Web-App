-- Nested Forum Lab: schema and fictional seed data.
-- Import once into a new, empty database (for example `nested_forum`).
-- MySQL 8.0+ or MariaDB 10.5+. All timestamps are stored and shown in UTC.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL,
    email         VARCHAR(190) NOT NULL,
    -- NULL means "no usable password": such an account can never log in.
    password_hash VARCHAR(255) NULL,
    -- NULL means "use the default avatar".
    avatar        VARCHAR(255) NULL,
    settings      JSON         NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY users_username_unique (username),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE discussions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT         NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT discussions_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    INDEX discussions_created (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    discussion_id INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    -- NULL = top-level comment (an edge from the discussion root).
    parent_id     INT UNSIGNED NULL,
    body          TEXT         NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Target for the composite foreign key below.
    UNIQUE KEY comments_id_discussion (id, discussion_id),
    CONSTRAINT comments_discussion_fk FOREIGN KEY (discussion_id) REFERENCES discussions (id),
    CONSTRAINT comments_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    -- A non-null parent must be a comment in the SAME discussion.
    -- When parent_id is NULL the constraint is not checked (top-level comment).
    -- This does not prevent cycles; the application never updates parent_id,
    -- and new replies can only point at comments that already exist.
    CONSTRAINT comments_parent_same_discussion_fk
        FOREIGN KEY (parent_id, discussion_id) REFERENCES comments (id, discussion_id),
    INDEX comments_tree (discussion_id, parent_id, created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Fictional seed data. Seed accounts have NULL password hashes, so nobody can
-- log in as them; register your own account to post. No real personal data.
-- ---------------------------------------------------------------------------
INSERT INTO users (id, username, email, password_hash) VALUES
    (1, 'Alice', 'alice@example.test', NULL),
    (2, 'Ben',   'ben@example.test',   NULL),
    (3, 'Cara',  'cara@example.test',  NULL),
    (4, 'Dee',   'dee@example.test',   NULL),
    (5, 'Eli',   'eli@example.test',   NULL);

INSERT INTO discussions (id, user_id, title, body, created_at) VALUES
    (1, 1, 'How do I learn PHP?', 'Share your learning tips.', '2026-11-01 08:00:00'),
    (2, 4, 'Favourite MySQL tools?', 'What do you use to browse and query MySQL?', '2026-11-02 09:00:00');

-- Discussion 1 matches the lab's reply tree (section 4.1).
-- Branch 1 -> 2 -> 3 -> 6 -> 7 reaches displayed depth 4.
INSERT INTO comments (id, discussion_id, user_id, parent_id, body, created_at) VALUES
    (1, 1, 1, NULL, 'Start with the PHP manual.',     '2026-11-01 08:10:00'),
    (2, 1, 2, 1,    'Try the examples as you read.',  '2026-11-01 08:20:00'),
    (3, 1, 3, 2,    'Build a small project too.',     '2026-11-01 08:30:00'),
    (4, 1, 4, 1,    'Which manual section first?',    '2026-11-01 08:40:00'),
    (5, 1, 5, NULL, 'Practice SQL as well.',          '2026-11-01 08:50:00'),
    (6, 1, 2, 3,    'Start with a forum.',            '2026-11-01 09:00:00'),
    (7, 1, 5, 6,    'Add replies next.',              '2026-11-01 09:10:00'),
    -- Discussion 2 is used to test the cross-discussion parent check.
    (8, 2, 3, NULL, 'phpMyAdmin for quick checks.',   '2026-11-02 09:15:00'),
    (9, 2, 1, 8,    'MySQL Workbench for diagrams.',  '2026-11-02 09:25:00');
