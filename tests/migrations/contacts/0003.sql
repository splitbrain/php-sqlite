INSERT INTO contacts (name, comment) VALUES ('Contact 1', 'in both groups');
INSERT INTO contacts (name, comment) VALUES ('Contact 2', 'in group 2');

INSERT INTO groups (name) VALUES ('Group 1');
INSERT INTO groups (name) VALUES ('Group 2');

INSERT INTO contact_groups (contact_id, group_id) VALUES (1, 1);
INSERT INTO contact_groups (contact_id, group_id) VALUES (1, 2);
INSERT INTO contact_groups (contact_id, group_id) VALUES (2, 2);
