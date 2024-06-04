CREATE TABLE questionaires (
    page TEXT NOT NULL PRIMARY KEY,
    activated_on TIMESTAMP NOT NULL,
    activated_by TEXT NOT NULL,
    deactivated_on TIMESTAMP DEFAULT 0,
    deactivated_by TEXT DEFAULT ''
);

CREATE TABLE answers (
    page TEXT NOT NULL REFERENCES questionaires(page),
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    answered_on TIMESTAMP NOT NULL,
    answered_by TEXT NOT NULL,
    PRIMARY KEY (page, question, answer, answered_by)
);
