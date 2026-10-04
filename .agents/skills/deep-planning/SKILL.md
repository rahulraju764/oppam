---
name: deep-planning
description: Performs detailed codebase exploration and creates an implementation-ready plan before complex features, refactors, migrations, or risky fixes.
---

# Deep Planning

Use this skill when a task:

- Changes multiple files or modules
- Has ambiguous requirements
- Changes public interfaces or stored data
- Affects authentication, payments, security, or deployment
- Requires an architectural decision

## Phase 1: Understand

1. Restate the objective.
2. Convert the request into measurable acceptance criteria.
3. Separate requirements from assumptions.
4. List unresolved questions.
5. Define explicit non-goals.

Do not modify files during this phase.

## Phase 2: Explore

Inspect:

- Relevant source files and symbols
- Callers and downstream consumers
- Existing tests
- Configuration and dependencies
- Similar implementations in the repository
- Error handling and data boundaries

Record findings with exact file paths and symbol names.

## Phase 3: Evaluate approaches

For each viable approach, describe:

- Benefits
- Costs
- Compatibility
- Failure modes
- Migration requirements
- Testing implications

Select one approach and explain why.

## Phase 4: Produce the plan

The plan must contain:

1. Objective
2. Current behavior
3. Proposed behavior
4. File-by-file changes
5. Interface or schema changes
6. Edge cases
7. Security and performance considerations
8. Testing strategy
9. Verification commands
10. Rollback strategy
11. Non-goals

Every step must have an observable completion condition.

## Phase 5: Challenge the plan

Before presenting the plan:

- Look for missing callers.
- Look for backward-compatibility problems.
- Look for race conditions and partial failures.
- Check whether a smaller solution exists.
- Confirm that tests would detect an incorrect implementation.

Do not implement until the user approves the plan.