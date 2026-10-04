# Engineering Instructions

## Operating principles

- Understand existing behavior before modifying code.
- Prefer the smallest complete solution.
- Follow existing architecture, naming, and style.
- Do not modify unrelated files.
- Do not add dependencies unless the benefit is explained.
- Never expose secrets, credentials, or private user data.
- Do not claim completion without verification evidence.

## Required workflow

For non-trivial work:

1. Inspect relevant code, tests, configuration, and documentation.
2. Restate the objective and acceptance criteria.
3. Identify uncertainties and ask only questions that materially affect the solution.
4. Produce a file-by-file implementation plan.
5. Identify risks, edge cases, and rollback considerations.
6. Wait for approval before implementation when the change is high-risk.
7. Implement the smallest coherent diff.
8. Run tests, linting, type checking, and build commands.
9. Review the final diff for unrelated changes.
10. Report evidence, limitations, and remaining risks.

## Planning requirements

A plan must include:

- Current behavior
- Desired behavior
- Relevant files and symbols
- Proposed changes by file
- Data flow and interface changes
- Edge cases and failure modes
- Security and performance implications
- Tests to add or update
- Verification commands
- Explicit non-goals

Do not write vague steps such as "update backend" or "fix UI."

## Definition of done

A task is complete only when:

- Acceptance criteria are satisfied.
- Relevant tests pass.
- The project builds successfully.
- No unrelated changes remain.
- User-visible behavior is verified where practical.
- The completion report includes actual command results.