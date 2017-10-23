CREATE TABLE project (
  id integer not null PRIMARY KEY,
  cms character (3) not NULL ,
  name character varying not NULL ,
  num_sites integer,
  declares_external_repos boolean,
  not_installable boolean,

  UNIQUE (name, cms)
);

CREATE TABLE package (
  id integer not null PRIMARY KEY,
  name character varying not null UNIQUE
);

CREATE TABLE project_package (
  project_id integer not null,
  package_id integer not null,
  is_direct  boolean not null,

  PRIMARY KEY (project_id, package_id),
  FOREIGN KEY (project_id) REFERENCES project(id),
  FOREIGN KEY (package_id) REFERENCES package(id)
);
