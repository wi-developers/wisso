// Mutation checks. For each mutant: apply the edit (the text to find must occur exactly once), run ONLY the test(s)
// meant to catch it, record whether one of them failed, restore the file. A mutant counts as caught only when an
// intended test fails (BitBox RW-09, RW-21): a different failure, or a run that did not execute, is not a catch.
// usage: node tools/mutate.mjs [mutant-id ...]    output: tools/mutation-results.json (+ a text table on stdout)
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const T = '\t';
const mutants = JSON.parse(readFileSync(join(root, 'tools', 'mutants.json'), 'utf8'));
const only = process.argv.slice(2);
const results = existsSync(join(root, 'tools', 'mutation-results.json'))
	? JSON.parse(readFileSync(join(root, 'tools', 'mutation-results.json'), 'utf8')) : {};

// Nextcloud caches its route table in APCu: a mutant of routes.php only takes effect after Apache is restarted.
function restartApache() {
	// apachectl restart signals the main process (SIGHUP) and the exec can report status 129 although the restart worked
	try {
		execFileSync('docker', ['compose', 'exec', '-T', 'app', 'apachectl', 'restart'], { cwd: join(root, '..', 'rig'), stdio: 'ignore', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
	} catch (e) { /* checked below */ }
	execFileSync('sleep', ['6']);
	execFileSync('docker', ['compose', 'exec', '-T', 'app', 'curl', '-sf', '-o', '/dev/null', 'http://localhost/status.php'], { cwd: join(root, '..', 'rig'), env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
}

function run(filter, outFile) {
	const sh = join(root, 'tools', 'run-tests.sh');
	try {
		execFileSync('sh', [sh, '--filter', filter], { env: { ...process.env, OUT: outFile }, encoding: 'utf8', timeout: 900000, stdio: ['ignore', 'pipe', 'pipe'] });
	} catch (e) { /* run-tests.sh prints the tail; the result is read from the output file */ }
	return execFileSync('docker', ['compose', 'exec', '-T', 'app', 'cat', outFile], { cwd: join(root, '..', 'rig'), encoding: 'utf8', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
}

for (const m of mutants) {
	if (only.length && !only.includes(m.id)) continue;
	const file = join(root, m.file);
	const original = readFileSync(file, 'utf8');
	let mutated = original;
	let applied = true;
	for (const [find, repl] of m.edits) {
		const f = find.replaceAll('\\n', '\n').replaceAll('\\t', T);
		const r = repl.replaceAll('\\n', '\n').replaceAll('\\t', T);
		const count = mutated.split(f).length - 1;
		if (count !== 1) { applied = false; results[m.id] = { ...m, status: 'NOT-APPLIED', note: `find text occurs ${count} times` }; break; }
		mutated = mutated.replace(f, () => r);
	}
	if (!applied) { console.log(m.id, 'NOT-APPLIED'); continue; }
	if (mutated === original) { results[m.id] = { ...m, status: 'NOT-APPLIED', note: 'no change' }; continue; }
	if (process.env.DRY) { console.log(m.id, 'applies'); continue; }
	writeFileSync(file, mutated);
	let output = '';
	try {
		if (m.restart) restartApache();
		output = run(m.also ? m.also + '|' + m.tests : m.tests, '/tmp/wisso-mutant.txt');
	} finally {
		writeFileSync(file, original);
		if (m.restart) restartApache();
	}
	const ran = /Tests: (\d+)/.test(output) || /OK \((\d+) test/.test(output);
	const failed = [...output.matchAll(/^\d+\) (\w+::\w+)/gm)].map((x) => x[1]);
	const intended = m.tests.split('|');
	const caughtBy = failed.filter((f) => intended.some((i) => f.endsWith('::' + i) || f.startsWith(i + '::')));
	let status;
	if (!ran) status = 'NO-RUN';
	else if (caughtBy.length) status = 'CAUGHT';
	else if (failed.length) status = 'FAILED-OTHER-TEST';
	else status = 'SURVIVED';
	results[m.id] = { id: m.id, rule: m.rule, file: m.file, tests: m.tests, status, failed };
	writeFileSync(join(root, 'tools', 'mutation-results.json'), JSON.stringify(results, null, 1));
	console.log(m.id.padEnd(5), status.padEnd(18), m.rule, failed.length ? ' <- ' + failed.join(', ') : '');
}
writeFileSync(join(root, 'tools', 'mutation-results.json'), JSON.stringify(results, null, 1));
