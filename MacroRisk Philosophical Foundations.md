# MacroRisk Philosophical Foundations

## Epistemology of constrained knowledge

MacroRisk is not a crystal ball.

A risk score is a model-derived output produced from explicit observations, transformations, configuration and mathematical rules. It does not measure the future.

The system must distinguish:
- what was observed;
- what was transformed;
- what the model calculated;
- what an analyst interprets;
- what remains a hypothesis.

Production text must not convert model output into certainty.

## Structural resilience

The system may identify situations in which observed stability depends on external replenishment rather than internal reproduction or productivity.

The model must state such relationships narrowly and operationally.

Preferred language:

> The model demonstrates structural dependence on migration replenishment.

Avoid rhetorical or political formulations.

## Determinism

Every production decision must be reproducible.

Transport timing is allowed to be nondeterministic. The persisted decision path is not.

## Scientific integrity

A missing observation is not evidence of zero risk.

A correlation is not automatically causation.

A model score is not a forecast guarantee.

The system must expose uncertainty and data limitations instead of hiding them.

## Scenario layer

The dashboard Scenario/Hypothesis Engine is interpretation plus hypothesis, never observation.

Slider overrides recompute a simulated score in `calculation_mode: simulation`. Triggered branches are labelled `classification: hypothesis`. Production text for that layer is screened for the banned-phrase list in `promt.txt` §8.

A simulated score must not be presented as the production score or as a forecast.
