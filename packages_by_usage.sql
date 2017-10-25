SELECT p.id, p.cms, p.name, p.num_sites
FROM project p
INNER JOIN (
 SELECT DISTINCT project_id FROM project_package WHERE is_direct = 1
) toplevel_projects ON project_id = p.id
WHERE num_sites > 300
ORDER BY p.num_sites DESC;
